# Secure Self-Hosted Inventory System Design

## Purpose

Turn the existing PHP inventory application into a safe, publicly shareable,
self-hosted product for individuals and small teams. Source code may be public,
but credentials, accounts, inventory records, backups, and runtime settings must
remain on each operator's own server.

The design must preserve existing MariaDB accounts and inventory data during an
upgrade. A fresh installation must also work without exposing database setup or
upgrade pages to the web.

## Users and tenancy

Each independent primary account owns one tenant. Inventory rows belong to that
tenant. Employee accounts have a `parent_id` that points to the primary account
and operate only on that tenant's inventory.

The initial installation creates one system administrator. That administrator
can manage global registration settings and invitation codes. Other primary
accounts can manage only their own inventory and employee accounts. The system
administrator is not given an application feature for browsing another tenant's
inventory, although the server and database owner can technically access all
local database data and backups.

Tenant checks must be applied on every read, create, update, status transition,
delete, batch operation, import, and account-management request. Record IDs are
never accepted as proof of ownership.

## Registration modes

The application supports three global modes:

- `closed`: no independent-account registration is available.
- `invite`: an unused, unexpired invitation code is required. This is the
  recommended and default deployment mode.
- `open`: anyone can create an independent primary account, subject to request
  throttling and account limits configured by the operator.

On the first deployment, `DEFAULT_REGISTRATION_MODE` seeds the database-backed
setting. Later, the initial system administrator changes the mode from the web
settings screen without restarting containers. Runtime settings and invitation
records live in MariaDB and are not committed to Git.

Invitation codes are random, stored as hashes, single-use by default, may have
an expiry, and may be revoked before use. The registration transaction consumes
the invite and creates the tenant atomically.

Employees are never created through public registration; a primary account
creates and manages its own employees.

## Deployment architecture

The recommended deployment is Docker Compose with two services:

1. An Apache/PHP application container.
2. A MariaDB container using a persistent local named volume.

The repository contains source code, a Dockerfile, Compose configuration,
health checks, migration tools, tests, and documentation. It does not contain a
real `.env`, database files, backups, sessions, logs, or credentials.

Advanced operators may point the application container at an existing MariaDB
server through the same environment variables. The migration path is identical
for bundled and external MariaDB.

Application container startup waits for the database, runs idempotent schema
migrations, creates the initial administrator only when the users table is
empty, and then starts Apache. A failed migration prevents the application from
starting and produces a clear container log rather than exposing SQL errors in
the browser.

Database storage is independent of the application container. Rebuilding or
updating the application must not delete inventory data. Documentation must
make destructive volume removal explicit and provide backup and restore
commands before upgrade instructions.

## Configuration and secrets

Database connection details and bootstrap credentials are read from environment
variables. `.env.example` contains placeholders and safe defaults only. `.env`
is ignored by Git.

Required deployment secrets include a strong MariaDB password and a strong
initial administrator password. Startup rejects documented placeholder values.
The password currently embedded in the legacy source must be removed and
rotated before the repository is published.

Browser-visible database errors are replaced with generic messages. Detailed
errors go only to container logs without printing passwords or connection
strings.

## Web security

All sessions use HTTP-only and SameSite cookies, strict session mode, session ID
rotation after authentication, and Secure cookies when HTTPS is enabled. The
deployment documentation recommends a trusted HTTPS reverse proxy.

Every state-changing request requires POST plus a per-session CSRF token.
Logout, finance relocking, password changes, registration settings, invitations,
imports, and all inventory mutations follow the same rule.

Only explicitly configured reverse proxies are trusted to supply a client IP.
Direct clients cannot choose their own rate-limit identity through
`X-Forwarded-For`.

Authentication and password-recovery endpoints use generic failure messages,
server-side throttling, minimum password requirements, and bounded input sizes.
Legacy password and security-answer hashes remain usable; successful legacy
verification upgrades a value to the current password hash format when needed.

Security headers block framing and MIME sniffing and set a conservative referrer
policy. Remote decorative images are removed so authentication pages work
offline and do not disclose visitor metadata to a third party.

## Authorization and validation

The API uses explicit action allowlists and request methods. Status values,
record IDs, pagination limits, list sizes, text lengths, quantities, and monetary
values are validated centrally.

For employee operations, the server verifies both source and destination
warehouse permissions. Batch operations first resolve all requested records
within the current tenant, reject missing or inaccessible IDs, and then mutate
inside a transaction.

Financial fields are masked by the server whenever the account lacks financial
visibility or the relevant warehouse is locked. The response must not include
an unmasked alternate field that allows reconstruction of hidden values.

All dynamic text inserted into HTML is escaped or assigned through text-safe DOM
APIs. API errors are not inserted as trusted markup.

## Database migration

The web-accessible `setup.php`, `fix_db.php`, and `upgrade.php` flows are retired.
Migration runs only from the command line/container entrypoint.

Migrations are versioned and idempotent. Before altering an existing database,
the tool records the schema version and checks current columns and indexes. It
adds missing fields and indexes without deleting inventory rows. Legacy columns
are retained unless a later migration can prove a lossless conversion.

The migration adds database-backed application settings and hashed invitation
records. Existing primary accounts become tenants using their current user IDs;
existing employee `parent_id` relationships and inventory `user_id` ownership
remain unchanged. Orphaned records are reported and left untouched for manual
review instead of being reassigned silently.

The old global unique service-number index is removed only after verification,
because the application permits tenant-scoped and parts/repair duplicates.
Pre-upgrade backup instructions are mandatory.

## User interface changes

The main workflow and visual layout remain familiar. Changes are limited to:

- hiding or showing the registration link according to the active mode;
- accepting an invitation code in invite mode;
- adding a system-administrator registration and invitation section;
- replacing the logout link with a protected POST action;
- displaying safe, actionable errors without internal database details.

No broad visual redesign is part of this work.

## Error handling and observability

API responses use consistent HTTP status codes and JSON error shapes. Expected
validation and authorization failures are safe for the user to read. Unexpected
exceptions receive a request identifier, are logged server-side, and return a
generic message.

Container health checks verify that the web process responds and that migrations
completed. Database readiness is checked separately from application health.

## Testing and release gates

Automated tests cover:

- configuration parsing and rejection of placeholder secrets;
- CSRF enforcement and method restrictions;
- password policy and trusted-proxy client IP handling;
- all three registration modes and invitation lifecycle;
- tenant isolation for reads and every mutation path;
- employee warehouse and financial permissions;
- new-database creation and migration of a representative legacy schema;
- preservation of existing accounts and inventory during migration;
- Docker image build and Compose configuration validation.

PHP syntax checks and focused unit/integration tests run in CI. Before release,
the application is exercised against a disposable MariaDB instance. Publishing
to GitHub happens only after tests pass, secrets are absent from history, and
the real legacy database credential has been rotated.

## Delivery and update workflow

The public repository uses a `main` branch and tagged releases. Installation is
documented as cloning the repository, creating a local `.env`, and running
Docker Compose. Updating is documented as backing up MariaDB, pulling the new
tag or branch, rebuilding the application container, and allowing the startup
migration to complete.

Creating the GitHub repository and pushing code requires the repository name and
the user's authenticated GitHub destination after local implementation and
verification are complete.

## Out of scope

- Hosted software-as-a-service billing or subscriptions.
- Physical database isolation for each tenant.
- Mobile applications.
- A system-admin feature to browse tenants' inventory.
- Automatic deletion of legacy data or Docker volumes.
