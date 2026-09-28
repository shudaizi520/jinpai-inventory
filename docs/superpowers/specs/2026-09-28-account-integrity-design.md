# Account Integrity and Audit Design

## Purpose

Strengthen the existing self-hosted inventory application for long-term use by small teams and invited independent tenants without changing its familiar account model or losing existing data. The upgrade must prevent stale concurrent writes, make inventory changes attributable and recoverable, revoke obsolete authenticated sessions, and enforce ownership relationships in MariaDB.

## Compatibility and Safety Constraints

- Existing primary accounts, employee accounts, permissions, inventory, registration settings, and invitations must be preserved.
- Primary accounts remain independent tenants. Employees continue to share exactly one inventory with their primary account.
- The first administrator remains the only global registration administrator and cannot self-delete in the application.
- Usernames remain globally unique because login does not include a tenant identifier.
- Existing Docker installation and URL remain unchanged.
- Database migrations must be idempotent. They must validate ownership before adding constraints and fail with a clear error instead of deleting or silently reassigning inconsistent rows.
- Deployment requires a database backup before the new image starts. A failed health or smoke check must leave the backup available and allow the prior application image to be restored.

## 1. Concurrent Inventory Writes and Synchronization

Each inventory row gains an integer `row_version`, starting at `1`. Every update increments it. Browser requests that act on existing rows carry the version last read by that browser. A request with an obsolete version is rejected with a conflict response and never overwrites newer data.

All inventory mutations run through a shared consistency layer that:

1. acquires a short-lived tenant-scoped MariaDB advisory lock;
2. starts a transaction;
3. locks the affected tenant rows;
4. validates tenant ownership, warehouse access, and expected row versions;
5. applies the change;
6. records the audit event;
7. advances the tenant inventory revision;
8. commits all effects together; and
9. releases the advisory lock even when an error occurs.

Adds, edits, status changes, batch edits, batch status changes, deletes, parts dispatch, imports, and restores must use this layer. Import remains an explicit upsert operation, but it serializes mutations for that tenant and reads the latest row while locked before deciding whether to insert or update.

A `tenant_inventory_state` table stores one monotonically increasing revision per primary account. The 10-second browser probe reads this single value instead of scanning inventory timestamps. Every committed mutation, including deletion and restoration, advances the revision. This removes the same-second timestamp gap and reduces polling cost as inventory grows.

The user interface continues to pause refresh while an edit or batch dialog is open. If the record changed before submission, the user sees a clear refresh-and-retry message.

## 2. Audit Log and Deleted-Item Recovery

An append-only `audit_events` table records meaningful inventory and employee-account changes. Each event stores:

- tenant identifier;
- actor user identifier when still available;
- actor username snapshot;
- action type;
- inventory item identifier and service number when applicable;
- JSON snapshots of the relevant state before and after the change;
- creation time; and
- restoration linkage for deletion events.

Inventory additions, edits, status changes, batch operations, imports, parts dispatch, deletions, and restores create one event per affected inventory item. Employee creation, permission/password changes, and deletion create account-management events without storing password hashes, lock passwords, security answers, CSRF tokens, or session data.

Primary accounts can view only their own tenant's audit events through a paginated operation-log panel. Employees cannot access this panel. A primary account can restore an inventory deletion event once. Restore creates a new live row from the saved business fields, validates the current warehouse rules and service-number conflict rules, links the new row to the deletion event, writes a restore audit event, and advances the tenant revision. Account self-deletion intentionally removes the tenant's audit history together with the tenant and is not recoverable from inside the application.

Audit entries are not editable through application endpoints.

## 3. Session Revocation

Each user gains a `session_version`, starting at `1`. Successful login stores the current value in the PHP session. Every authenticated page and API request compares the session value with the current database value.

- When a user changes their own password, `session_version` increments and the current session adopts the new value; other sessions become invalid.
- When a primary account changes an employee password or permissions, the employee's `session_version` increments; every existing employee session is rejected on its next request.
- Password recovery increments `session_version`, invalidating every previous session.
- Deleting an employee already invalidates sessions because the user row no longer exists.

Session rejection clears the current PHP session and returns the existing login-expired behavior. No persistent session identifiers are stored in the database.

## 4. Database Ownership Constraints

Top-level accounts continue to use `0` for `users.parent_id`, preserving compatibility with the currently deployed release and making application rollback safe. An index and database triggers enforce that every non-zero parent exists and is itself top-level. A delete guard rejects direct deletion of a primary account while employees still reference it; the existing transactional account-deletion flow removes employees first.

`inventory_items.user_id` references the owning primary account and uses cascading deletion. A database trigger rejects inventory ownership by an employee account, because a foreign key alone cannot express that rule. `tenant_inventory_state` and `audit_events` reference the owning primary account and are deleted when that primary account is deleted. Audit actor identity is also preserved as a username snapshot so deleting an employee does not erase attribution.

MariaDB cannot express the legacy `0` sentinel as a conventional self-referencing foreign key without changing values that the previous release expects. Triggers with `SIGNAL SQLSTATE '45000'` therefore enforce the parent relationship, while conventional foreign keys enforce inventory, tenant-state, and audit ownership. This keeps the deployed schema readable by both the new and immediately previous application releases.

Before constraints are installed, migration checks for:

- inventory rows whose owner does not exist;
- employees whose parent does not exist;
- nested employee relationships;
- inventory rows owned by employees.

Any finding aborts startup with counts and recovery guidance. No automatic destructive repair is permitted.

## Authorization Behavior

The existing server-side tenant and permission checks remain authoritative. Every API request reloads the current user. Database constraints supplement these checks; they do not replace them.

The system administrator controls global registration settings but does not gain access to other tenants' inventory or audit data. Every primary account manages only its own employees, inventory, deleted-item recovery, and audit history.

## Error Handling

- Stale writes return HTTP 409 with a user-facing refresh message.
- Missing, malformed, or mismatched version maps return HTTP 400 or 409 without partial changes.
- Batch operations are all-or-nothing.
- Audit or revision failure rolls back the inventory mutation.
- Migration validation errors stop the new container before Apache starts.
- Sensitive exception details remain in server logs behind opaque request identifiers.

## Testing

Tests are written before implementation and must cover:

- same-tenant primary/employee synchronization and cross-tenant rejection;
- stale single and batch writes, same-second updates, and atomic rollback;
- revision changes for every mutation and O(1) update probes;
- audit attribution, sensitive-field exclusion, tenant isolation, deletion recovery, and one-time restore;
- password, password-recovery, permission-change, account-deletion, and unchanged-session behavior;
- fresh and legacy migrations, invalid ownership rejection, foreign-key enforcement, cascades, and idempotence;
- current CSRF, finance masking, warehouse locks, registration modes, Docker build, packaging, and secret scanning regressions.

The release gate is the complete local suite plus GitHub CI. NAS deployment occurs only after CI succeeds for the exact pushed commit. Authenticated inventory, employee isolation, audit, session-revocation, and recovery checks run against disposable integration databases before deployment. Post-deployment checks cover container health, login redirect, schema state, recent logs, and a synthetic database transaction that is fully rolled back. The deployment process never requests or uses the owner's password and does not add test records to a real tenant.

## Rollback

Deployment records the old application image tag and creates a timestamped SQL backup first. If application health or smoke tests fail, the previous image is restored. The migration retains the existing `parent_id=0` representation and only adds backward-compatible columns, tables, indexes, foreign keys, and triggers, so application rollback does not require an immediate destructive database downgrade. Database restoration is reserved for migration failure or confirmed data corruption and requires explicit validation of the backup timestamp.
