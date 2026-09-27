# Secure Self-Hosted Inventory Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert the legacy PHP inventory application into a secure, migration-safe, Dockerized public project whose credentials and tenant data remain local.

**Architecture:** Keep the existing PHP user interface and MariaDB data model, but introduce focused configuration, security, authorization, registration, and migration modules around it. Deploy Apache/PHP and MariaDB through Docker Compose with persistent storage, versioned CLI migrations, bootstrap-admin creation, automated security tests, and no web-accessible maintenance scripts.

**Tech Stack:** PHP 8.4, Apache, PDO MySQL, MariaDB 11.4 LTS, Docker Compose, Bash entrypoint, framework-free PHP test runner, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-09-27-secure-self-hosted-inventory-design.md`

## Global Constraints

- Preserve all existing `users`, `inventory_items`, ownership relationships, and inventory values during migration.
- Do not commit real `.env` files, credentials, database files, backups, logs, or sessions.
- Default registration mode is `invite`; employees are created only by their primary account.
- Every state-changing HTTP request is POST plus a per-session CSRF token.
- Every inventory request is scoped by the authenticated tenant and employee warehouse permissions.
- Web responses never expose SQL connection details or stack traces.
- Runtime has no dependency on remote decorative images or third-party CDNs.
- Docker volume deletion and other destructive operations are never automated.

## Review Focus

- A legacy schema missing any newer column or index must migrate without deleting rows or silently assigning orphaned rows.
- Empty, duplicated, non-numeric, or oversized ID lists must be rejected before constructing batch SQL.
- Expired, revoked, previously used, or concurrently submitted invitation codes must never create an account.
- An untrusted client-supplied `X-Forwarded-For` value must not change the rate-limit identity.
- An employee guessing another warehouse's row ID must not read, update, transition, delete, import over, or reconstruct masked monetary data.

---

### Task 1: Configuration and security primitives

**Files:**
- Create: `php/lib/config.php`
- Create: `php/lib/security.php`
- Create: `php/bootstrap.php`
- Modify: `php/db_config.php`
- Create: `tests/TestRunner.php`
- Create: `tests/unit/security_test.php`
- Create: `tests/run.php`

**Interfaces:**
- Produces: `app_config(string $key, mixed $default = null): mixed`, `app_pdo(): PDO`, `start_secure_session(): void`, `csrf_token(): string`, `require_csrf(): void`, `client_ip(array $server, array $trustedProxies): string`, `validate_password(string $password): array`, `json_response(array $payload, int $status = 200): never`, `safe_log(Throwable $error): string`.

- [ ] **Step 1: Write failing unit tests for environment parsing, placeholder-secret rejection, CSRF comparison, 12-character password policy, generic logged errors, and trusted-proxy IP selection.**

- [ ] **Step 2: Run `tests/run.php tests/unit/security_test.php` and confirm failures identify missing functions.**

- [ ] **Step 3: Implement the focused configuration and security modules, secure session defaults, security headers, generic browser errors, and PDO creation with native prepared statements.**

- [ ] **Step 4: Replace hard-coded database settings in `db_config.php` with `app_pdo()` and verify the former database password is absent from the working tree.**

- [ ] **Step 5: Run the unit test, PHP syntax checks for all PHP files, and `git diff --check`; expect zero failures.**

- [ ] **Step 6: Commit with message `feat: add secure configuration foundation`.**

### Task 2: Idempotent database migration and administrator bootstrap

**Files:**
- Create: `php/lib/migrations.php`
- Create: `scripts/migrate.php`
- Create: `scripts/bootstrap-admin.php`
- Create: `tests/integration/migration_test.php`
- Remove: `php/setup.php`
- Remove: `php/fix_db.php`
- Remove: `php/upgrade.php`

**Interfaces:**
- Consumes: `app_pdo()`, `app_config()`, `validate_password()` from Task 1.
- Produces: `run_migrations(PDO $pdo): void`, `bootstrap_admin(PDO $pdo, string $username, string $password): bool`, schema tables `schema_migrations`, `app_settings`, and `registration_invites`.

- [ ] **Step 1: Write integration tests for a fresh schema and a representative legacy schema, asserting row counts and inventory values are unchanged after two migration runs.**

- [ ] **Step 2: Run the migration tests against a disposable MariaDB service and confirm they fail before migration code exists.**

- [ ] **Step 3: Implement versioned, idempotent migrations that create missing tables and columns, preserve legacy columns and rows, add required indexes, report orphans, and safely remove only the obsolete global unique service-number index.**

- [ ] **Step 4: Implement CLI-only migration and bootstrap-admin commands; bootstrap only when no users exist and reject weak or placeholder credentials.**

- [ ] **Step 5: Remove web maintenance scripts and rerun migration tests twice against fresh and legacy fixtures; expect no data loss and no second-run changes.**

- [ ] **Step 6: Commit with message `feat: add safe database migrations`.**

### Task 3: Registration modes and invitation lifecycle

**Files:**
- Create: `php/lib/registration.php`
- Modify: `php/register.php`
- Modify: `php/login.php`
- Create: `tests/unit/registration_test.php`
- Create: `tests/integration/invitation_test.php`

**Interfaces:**
- Consumes: security helpers and `app_settings`, `registration_invites` from Tasks 1-2.
- Produces: `registration_mode(PDO $pdo): string`, `set_registration_mode(PDO $pdo, int $actorId, string $mode): void`, `create_invitation(PDO $pdo, int $actorId, ?DateTimeImmutable $expiresAt): array`, `consume_invitation(PDO $pdo, string $plainCode, callable $createAccount): int`, `list_invitations(PDO $pdo): array`, `revoke_invitation(PDO $pdo, int $inviteId): void`.

- [ ] **Step 1: Write failing tests for `closed`, `invite`, and `open` modes plus expired, revoked, reused, invalid, and concurrent invitation submissions.**

- [ ] **Step 2: Run registration tests and confirm the missing lifecycle implementation fails.**

- [ ] **Step 3: Implement database-backed mode selection and cryptographically random, hashed, single-use invitation codes consumed in the same transaction as account creation.**

- [ ] **Step 4: Update registration UI and endpoint: closed mode refuses access, invite mode requires a code, open mode is throttled, all modes enforce input lengths and password policy, and employee creation remains private.**

- [ ] **Step 5: Update the login page registration link based on active mode and run all registration tests; expect zero failures.**

- [ ] **Step 6: Commit with message `feat: add controlled tenant registration`.**

### Task 4: Authentication, sessions, CSRF, and recovery hardening

**Files:**
- Modify: `php/login.php`
- Modify: `php/logout.php`
- Modify: `php/index.php`
- Modify: `php/api_inventory.php`
- Create: `tests/unit/auth_test.php`
- Create: `tests/integration/csrf_test.php`

**Interfaces:**
- Consumes: `start_secure_session()`, `csrf_token()`, `require_csrf()`, `client_ip()`, and password helpers from Task 1.
- Produces: protected form and API flows in which GET is read-only and all mutations require POST plus CSRF.

- [ ] **Step 1: Write failing tests for missing/invalid CSRF tokens, GET mutation attempts, session rotation, proxy spoofing, weak replacement passwords, logout, and password-recovery throttling.**

- [ ] **Step 2: Run authentication and CSRF tests and confirm legacy behavior fails the new assertions.**

- [ ] **Step 3: Route every entrypoint through `bootstrap.php`, issue CSRF tokens, add a JS request wrapper that sends the token for form and JSON requests, and convert logout/relock to protected POST.**

- [ ] **Step 4: Trust forwarded IP headers only from configured proxies; normalize authentication errors; enforce password policy on register, reset, change, and employee password creation; retain legacy hash verification and rehash on success.**

- [ ] **Step 5: Run authentication, CSRF, and full unit suites plus syntax checks; expect zero failures.**

- [ ] **Step 6: Commit with message `fix: harden authentication and sessions`.**

### Task 5: Tenant authorization, warehouse permissions, and input validation

**Files:**
- Create: `php/lib/authorization.php`
- Modify: `php/api_inventory.php`
- Create: `tests/unit/authorization_test.php`
- Create: `tests/integration/tenant_isolation_test.php`

**Interfaces:**
- Consumes: authenticated current-user and owner records from `bootstrap.php`.
- Produces: `tenant_id(array $user): int`, `allowed_statuses(array $user, array $owner): array`, `require_status_access(string $status, array $user, array $owner): void`, `parse_ids(string $value, int $maximum = 200): array`, `validate_inventory_input(array $input): array`, `mask_financial_fields(array $row): array`.

- [ ] **Step 1: Write failing tests for guessed foreign IDs, hidden source/destination warehouses, invalid statuses, oversized pagination/import/batch inputs, duplicate IDs, and masking every monetary field.**

- [ ] **Step 2: Run authorization tests and confirm the legacy API permits at least the identified bypass cases.**

- [ ] **Step 3: Implement central status and tenant policy helpers, bounded validators, and complete financial masking.**

- [ ] **Step 4: Refactor every API action to use explicit request methods, resolve records within the tenant, validate source and destination status access, reject partial batch matches, and use transactions for multi-row mutations.**

- [ ] **Step 5: Run unit and MariaDB-backed tenant tests for list, save, transition, delete, batch edit/delete/transition, parts dispatch, and import; expect all cross-tenant and hidden-warehouse attempts to be denied.**

- [ ] **Step 6: Commit with message `fix: enforce tenant isolation across inventory API`.**

### Task 6: Safe rendering and system-administrator settings UI

**Files:**
- Modify: `php/index.php`
- Modify: `php/login.php`
- Modify: `php/register.php`
- Modify: `php/api_inventory.php`
- Create: `tests/unit/rendering_test.php`
- Create: `tests/integration/admin_settings_test.php`

**Interfaces:**
- Consumes: registration lifecycle and authorization helpers from Tasks 3 and 5.
- Produces: system-admin-only API actions and UI for registration mode, invitation creation/list/revocation, plus text-safe rendering utilities.

- [ ] **Step 1: Write failing tests using HTML/JavaScript payloads in usernames, inventory text, and API messages, and tests denying non-system-admin registration-setting actions.**

- [ ] **Step 2: Run rendering/admin tests and confirm unsafe subaccount rendering and missing admin controls fail.**

- [ ] **Step 3: Escape or text-render all dynamic values, remove remote authentication background images, and prevent API errors from entering `innerHTML` unescaped.**

- [ ] **Step 4: Add the system-admin settings panel and protected endpoints for changing registration mode and managing invitations without enabling cross-tenant inventory access.**

- [ ] **Step 5: Run rendering, admin, and full test suites plus a static search of HTML sinks; expect only reviewed constant-template sinks.**

- [ ] **Step 6: Commit with message `feat: add secure registration administration`.**

### Task 7: Docker packaging and local persistent data

**Files:**
- Create: `Dockerfile`
- Create: `compose.yaml`
- Create: `.dockerignore`
- Create: `.env.example`
- Create: `docker/apache-security.conf`
- Create: `scripts/docker-entrypoint.sh`
- Create: `scripts/healthcheck.php`
- Create: `tests/docker/compose_test.sh`

**Interfaces:**
- Consumes: CLI migrations and bootstrap command from Task 2.
- Produces: PHP 8.4 Apache application image, MariaDB 11.4 service, local persistent database volume, startup migration, and health checks.

- [ ] **Step 1: Write the Docker validation script to assert secret files are excluded, Compose resolves, persistent volume exists, migrations precede Apache, and health checks are configured.**

- [ ] **Step 2: Run the Docker validation script and confirm it fails while packaging files are absent.**

- [ ] **Step 3: Implement the image, Apache restrictions, Compose services, safe example environment, entrypoint database wait/migrate/bootstrap flow, and application health check.**

- [ ] **Step 4: Build and start the stack with generated disposable secrets; verify healthy services, initial admin login prerequisites, and database persistence across application-container recreation.**

- [ ] **Step 5: Run Docker validation, full PHP tests, syntax checks, and `docker compose config`; expect zero failures.**

- [ ] **Step 6: Commit with message `feat: add production Docker deployment`.**

### Task 8: Public repository documentation, CI, and release verification

**Files:**
- Create: `.gitignore`
- Create: `README.md`
- Create: `SECURITY.md`
- Create: `LICENSE`
- Create: `.github/workflows/ci.yml`
- Create: `scripts/backup.sh`
- Create: `scripts/restore.sh`
- Modify: `php/assets/xlsx.full.min.js` only if a verified compatible security update is available.

**Interfaces:**
- Consumes: all previous tasks.
- Produces: documented install, invite, backup, restore, external-MariaDB, update, and vulnerability-reporting workflows plus automated CI gates.

- [ ] **Step 1: Add tests/static checks that fail when tracked secrets, real `.env` files, database dumps, unsupported web maintenance scripts, or missing required documentation are detected.**

- [ ] **Step 2: Run the release checks and confirm they fail before documentation and CI files exist.**

- [ ] **Step 3: Write user-focused installation, configuration, registration-mode, invitation, backup, restore, migration, HTTPS proxy, update, rollback, and troubleshooting documentation; add a compatible open-source license and security policy.**

- [ ] **Step 4: Add CI for PHP syntax, unit tests, MariaDB integration tests, secret scanning, and Docker build/Compose validation.**

- [ ] **Step 5: Run the complete verification matrix on a clean disposable stack, inspect the staged Git tree for secrets and generated data, and verify the legacy credential is absent.**

- [ ] **Step 6: Commit with message `docs: prepare public inventory release`.**

- [ ] **Step 7: Record the remaining external handoff: obtain the desired public GitHub repository URL/name, rotate the real MariaDB password, push `main`, and attach any created pull request.**
