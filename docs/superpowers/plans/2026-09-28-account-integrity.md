# Account Integrity and Audit Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add complete inventory concurrency protection, tenant-scoped audit and deletion recovery, obsolete-session revocation, and database-enforced ownership without losing current NAS data.

**Architecture:** Add backward-compatible schema primitives first, then centralize session validation and inventory mutation consistency in focused library files. Existing endpoints keep their URLs and user-facing workflow, while all writes use tenant transactions, row versions, revision bumps, and append-only audit events.

**Tech Stack:** PHP 8.4, PDO, MariaDB 11.4/InnoDB, server-rendered JavaScript, Docker Compose, the repository's custom PHP test runner.

**Spec:** `docs/superpowers/specs/2026-09-28-account-integrity-design.md`

## Global Constraints

- Preserve all existing accounts, permissions, inventory, settings, and invitations.
- Keep `users.parent_id=0` for primary accounts so the previous release can read the upgraded database.
- Never log password hashes, lock passwords, security answers, CSRF tokens, session identifiers, or database secrets.
- Every inventory mutation, audit event, and tenant revision change commits or rolls back as one unit.
- Migration validation aborts on inconsistent ownership and never deletes or silently reassigns rows.
- Keep the existing URL, Docker application name, dedicated volume, and registration/account workflow.
- Write and observe each failing test before production code; keep the full suite green after each task.

## Review Focus

- A same-second competing edit must return HTTP 409 rather than overwrite data; covered in Task 3 endpoint tests.
- A mixed-current/stale batch must roll back every row; covered in Task 3 integration tests.
- Audit JSON must exclude every authentication and financial-lock secret; covered in Task 4 unit and integration tests.
- Changing an employee password or permissions must revoke old sessions without revoking the owner's session; covered in Task 2 endpoint tests.
- A dirty legacy database must stop migration with all original rows intact; covered in Task 1 migration tests.

---

### Task 1: Backward-Compatible Integrity Schema

**Files:**
- Modify: `php/lib/migrations.php`
- Modify: `tests/integration/migration_test.php`

**Interfaces:**
- Produces: `users.session_version`, `inventory_items.row_version`, `tenant_inventory_state`, `audit_events`, ownership foreign keys, and ownership validation triggers.
- Produces: `validate_account_integrity_ownership(PDO $pdo): void` and `migrate_account_integrity(PDO $pdo): void` for later tasks.

- [ ] **Step 1: Write failing clean-upgrade and idempotence tests**

Add assertions that a fresh and a valid legacy database gain the two columns, two tables, indexes, named constraints/triggers, and the next migration version while preserving values across two `run_migrations()` calls.

- [ ] **Step 2: Run the migration test and verify RED**

Run the integration migration test against MariaDB 11.4. Expected: failures for missing columns/tables/constraints and the old migration count.

- [ ] **Step 3: Implement the additive schema migration**

Add columns with default `1`; create `tenant_inventory_state` and `audit_events`; seed tenant state for existing primary accounts; add supporting indexes and idempotent named constraints/triggers. Keep `parent_id=0` unchanged.

- [ ] **Step 4: Run the migration test and verify GREEN**

Expected: fresh and valid legacy migration cases pass twice without duplicate objects.

- [ ] **Step 5: Write failing invalid-ownership tests**

Cover missing employee parent, nested employee, missing inventory owner, and inventory owned by an employee. Assert migration throws and all inserted rows remain unchanged.

- [ ] **Step 6: Run the invalid-ownership tests and verify RED**

Expected: migration currently accepts at least one invalid fixture.

- [ ] **Step 7: Implement validation and database enforcement**

Validate all four cases before DDL. Add user insert/update/delete guards and inventory owner insert/update guards using `SIGNAL SQLSTATE '45000'`; add conventional ownership foreign keys where the legacy sentinel does not interfere.

- [ ] **Step 8: Run migration tests and the full current suite**

Expected: all migration, unit, and existing integration tests pass with no warnings.

- [ ] **Step 9: Commit**

Commit message: `feat: enforce account ownership in database`

### Task 2: Session-Version Revocation

**Files:**
- Modify: `php/lib/auth.php`
- Modify: `php/index.php`
- Modify: `php/api_inventory.php`
- Modify: `php/login.php`
- Modify: `tests/unit/auth_test.php`
- Create: `tests/integration/session_revocation_test.php`
- Modify: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: `users.session_version` from Task 1.
- Produces: `establish_authenticated_session()` stores `session_version`; `require_current_session_user(PDO $pdo): array`; `advance_session_version(PDO $pdo, int $userId): int`.

- [ ] **Step 1: Write failing unit tests for session establishment and version comparison**

Assert login stores the database version, matching sessions return the user, deleted/mismatched users clear authentication, and the returned error remains the existing login-expired behavior.

- [ ] **Step 2: Run the focused unit tests and verify RED**

Expected: missing session-version functions or absent session value.

- [ ] **Step 3: Implement centralized current-session validation**

Add the focused helpers to `auth.php`; use the validator at the start of `index.php` and `api_inventory.php` instead of separate user/session existence checks.

- [ ] **Step 4: Run focused tests and verify GREEN**

- [ ] **Step 5: Write failing endpoint tests for revocation paths**

Create two sessions for one employee and separate owner sessions. Test employee password reset, employee permission change, self password change, and login-page password recovery. Assert old employee/user sessions fail on their next request while the acting current session behaves as specified.

- [ ] **Step 6: Run endpoint tests and verify RED**

Expected: old sessions remain accepted before implementation.

- [ ] **Step 7: Implement version advancement at every credential/permission mutation**

Increment within the same transaction as password or employee permission changes. Update the current session version only for a successful self-change. Do not alter sessions on unrelated settings changes.

- [ ] **Step 8: Add the new integration file to CI and run unit plus integration suites**

Expected: revocation and all existing authentication/rate-limit tests pass.

- [ ] **Step 9: Commit**

Commit message: `feat: revoke obsolete account sessions`

### Task 3: Inventory Versions and O(1) Tenant Revisions

**Files:**
- Create: `php/lib/inventory_consistency.php`
- Modify: `php/lib/authorization.php`
- Modify: `php/api_inventory.php`
- Modify: `php/index.php`
- Create: `tests/unit/inventory_consistency_test.php`
- Create: `tests/integration/inventory_concurrency_test.php`
- Modify: `tests/integration/api_endpoint_test.php`
- Modify: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: `inventory_items.row_version` and `tenant_inventory_state` from Task 1.
- Produces: `with_tenant_inventory_mutation(PDO $pdo, int $tenantId, callable $operation): mixed`, `parse_version_map(mixed $value, array $ids): array`, `require_expected_version(array $item, int $expected): void`, `tenant_inventory_revision(PDO $pdo, int $tenantId): int`, and `bump_tenant_inventory_revision(PDO $pdo, int $tenantId): int`.

- [ ] **Step 1: Write failing unit tests for strict version parsing and stale detection**

Cover missing IDs, extra IDs, duplicates, zero/negative/overflow versions, malformed JSON, and an exact valid map. Assert stale versions raise HTTP 409.

- [ ] **Step 2: Run focused tests and verify RED**

- [ ] **Step 3: Implement the consistency helper**

Use a tenant-scoped `GET_LOCK` name with a five-second timeout, guaranteed `RELEASE_LOCK`, transaction ownership checks, strict version helpers, and insert-or-increment tenant revision logic.

- [ ] **Step 4: Run focused tests and verify GREEN**

- [ ] **Step 5: Write failing concurrency integration tests**

Using two PDO connections, prove same-second stale single updates fail, mixed stale/current batches roll back, concurrent duplicate main-flow service numbers cannot be inserted, revisions advance on committed mutations only, and `check_update` returns the revision without depending on `updated_at`.

- [ ] **Step 6: Run integration tests and verify RED**

- [ ] **Step 7: Route every inventory mutation through the helper**

Update add/edit, single and batch status, single and batch delete, batch edit, parts dispatch, import, and later restore paths. Existing-row updates include `row_version = row_version + 1`; all expected versions are checked while rows are locked.

- [ ] **Step 8: Send versions from the browser**

Keep `row_version` in `currentData`; submit it for single actions and a JSON ID-to-version map for batch actions. Preserve modal refresh pausing and display HTTP 409 as a refresh-and-retry message.

- [ ] **Step 9: Replace the timestamp/count update probe**

Make `check_update` return the tenant revision. Ensure permission checks and inactive-page behavior remain unchanged.

- [ ] **Step 10: Run concurrency, endpoint, tenant-isolation, and full suites**

Expected: all mutation paths reject stale/cross-tenant input atomically and existing inventory behavior remains green.

- [ ] **Step 11: Commit**

Commit message: `feat: prevent concurrent inventory overwrites`

### Task 4: Tenant Audit Log and One-Time Delete Recovery

**Files:**
- Create: `php/lib/audit.php`
- Modify: `php/lib/auth.php`
- Modify: `php/api_inventory.php`
- Modify: `php/index.php`
- Create: `tests/unit/audit_test.php`
- Create: `tests/integration/audit_recovery_test.php`
- Modify: `tests/integration/api_endpoint_test.php`
- Modify: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: the mutation transaction and revision helpers from Task 3 and `audit_events` from Task 1.
- Produces: `inventory_audit_snapshot(array $item): array`, `account_audit_snapshot(array $user): array`, `record_audit_event(...)`, `list_tenant_audit_events(...)`, and `restore_deleted_inventory(...)`.

- [ ] **Step 1: Write failing unit tests for snapshot allowlists**

Assert inventory snapshots contain only restorable business fields and account snapshots contain only username/role/permission fields. Explicitly assert password, lock, security-answer, CSRF, session, and database-secret names/values are absent.

- [ ] **Step 2: Run focused tests and verify RED**

- [ ] **Step 3: Implement allowlisted snapshots and append-only recording**

Serialize with JSON exceptions enabled. Keep audit writes inside the caller's transaction and never accept raw client JSON as an audit snapshot.

- [ ] **Step 4: Run focused tests and verify GREEN**

- [ ] **Step 5: Write failing integration tests for attribution and isolation**

Cover owner and employee mutations, one row per affected batch item, employee-account management, hidden foreign-tenant logs, employee denial, deletion snapshots, successful one-time restore, repeated restore rejection, and service-number conflict rejection.

- [ ] **Step 6: Run integration tests and verify RED**

- [ ] **Step 7: Add audit writes to inventory and employee-account mutations**

Record actor ID and username snapshot plus before/after allowlisted data. Account self-deletion cascades its tenant audit history and does not attempt to retain secrets.

- [ ] **Step 8: Add paginated primary-account audit APIs and recovery**

Add read action `list_audit_events` and mutation `restore_deleted_inventory`; enforce primary-account tenant ownership, one-time restore linkage, current status rules, conflict rules, audit write, row version, and revision bump.

- [ ] **Step 9: Add the operation-log panel**

Expose it only to primary accounts. Show time, actor, action, service number, and a restore button only for eligible deletion events; escape all dynamic text and handle conflicts without reloading unrelated settings.

- [ ] **Step 10: Run audit, rendering, endpoint, isolation, and full suites**

Expected: logs are attributable and isolated, secrets are absent, restore is atomic, and all existing UI security tests pass.

- [ ] **Step 11: Commit**

Commit message: `feat: add tenant audit and delete recovery`

### Task 5: Documentation, Release Validation, and GitHub Publication

**Files:**
- Modify: `README.md`
- Modify: `tests/release/release_test.sh`
- Modify: any files identified by final review, limited to this feature's scope

**Interfaces:**
- Consumes: all prior tasks.
- Produces: documented operator behavior and a release-ready exact commit.

- [ ] **Step 1: Write failing release checks for required migration/audit/session files**

Assert the release contains the new libraries and that Docker packaging copies them into both web and migration execution paths.

- [ ] **Step 2: Run release checks and verify RED**

- [ ] **Step 3: Update operator documentation and packaging checks**

Document 10-second synchronization, conflict messages, operation logs/recovery, forced sign-out behavior, migration validation, backup, and rollback expectations without exposing deployment secrets.

- [ ] **Step 4: Run the complete local release gate**

Run PHP lint, unit tests, every integration test against MariaDB 11.4, Docker/release/maintenance checks, Compose validation, and Docker build. Expected: zero failures and no warnings.

- [ ] **Step 5: Review the complete branch**

Use `superpowers:requesting-code-review`; fix every confirmed high/medium issue with a failing regression test first, then repeat the release gate.

- [ ] **Step 6: Commit final documentation/review fixes**

Commit message: `docs: document account integrity controls`

- [ ] **Step 7: Push the feature branch and wait for exact-commit CI**

Push `feature/account-integrity`; require syntax, unit, integration, packaging, Compose, Docker build, and secret scan success for the branch HEAD.

- [ ] **Step 8: Publish the verified commit to `origin/main`**

Fast-forward `origin/main` from the verified feature HEAD and wait for the main-branch CI run to succeed before deployment.

### Task 6: Backed-Up NAS Deployment and Smoke Verification

**Files:**
- No repository files expected.
- Create outside Git: timestamped NAS SQL backup and deployment record.

**Interfaces:**
- Consumes: exact successful `origin/main` commit from Task 5.
- Produces: healthy TrueNAS app using that commit, with a verified backup and prior image tag retained for rollback.

- [ ] **Step 1: Capture current deployment state**

Record current app state, container image tag, Git commit, health response, and dedicated database volume path without printing credentials.

- [ ] **Step 2: Create and verify a timestamped SQL backup**

Use the repository backup procedure inside the TrueNAS application context. Verify non-zero file size and gzip/SQL readability; do not display database secrets or row contents.

- [ ] **Step 3: Build and stage the exact verified commit**

Pull `origin/main`, build a new immutable `jinpai-inventory-app:<short-sha>` image, update the custom app configuration while preserving the dedicated database volume and secrets, and retain the previous image tag.

- [ ] **Step 4: Start the new containers and wait for health**

Require both containers running, application health `ok`, and root redirect to login. If migration or health fails, restore the previous image and report without attempting ad-hoc data repair.

- [ ] **Step 5: Run a rolled-back production-schema smoke transaction**

Inside one database transaction, create synthetic owner/employee/inventory rows, exercise ownership constraints, row versions, revisions, audit writes, and cascades, then roll back the entire transaction. Do not request the owner's password, read business rows, or leave test records in a real tenant. Authenticated HTTP behavior is already required to pass against disposable databases in Tasks 2 through 4 and the complete release gate.

- [ ] **Step 6: Recheck resource use and logs**

Capture application memory/CPU, recent 4xx/5xx and PHP/MariaDB errors, volume presence, and health after smoke testing.

- [ ] **Step 7: Use `superpowers:verification-before-completion`**

Only after fresh GitHub CI, deployment health, smoke checks, and logs are clean, report the deployed commit, URL, backup location, and any observed limitations.
