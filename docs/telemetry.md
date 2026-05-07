## Telemetry ##

Atomic telemetry is a built-in diagnostics panel that provides live visibility into the application's runtime state, queue jobs, and log output. It is a separate read-only interface served under `/telemetry`, distinct from the main application.

All data returned by telemetry endpoints is sanitized through the Redactor before
being served - sensitive values, secrets, and filesystem paths are masked.

### Access

Telemetry is public by default:

```env
TELEMETRY_ACCESS_MODE=none
```

It can be hidden in two ways:

- `config` - use framework config users (`access:telemetry`) and a role whitelist.
- `auth` - use the application's auth system and a role whitelist.

Config-user middleware behavior:
[`middleware.md#config-backed-access-middleware`](middleware.md#config-backed-access-middleware).

CLI for creating and maintaining config users:
[Config user commands](#config-user-commands).

Allowed roles are configured with one list. The default allowed role is `admin`.
Every protected telemetry route uses the same list.

Env loader:

```env
TELEMETRY_ACCESS_MODE=auth
TELEMETRY_ACCESS_ALLOWED_ROLES=admin
```

Multiple env roles are comma-separated:

```env
TELEMETRY_ACCESS_ALLOWED_ROLES=admin,support,viewer
```

PHP config loader:

```php
'telemetry' => [
    'access_mode' => 'auth',
    'access_allowed_roles' => ['admin'],
],
```

In `auth` mode, the application must register its normal auth user provider and
the current user must implement `HasRolesInterface`.

### Config user commands

When `TELEMETRY_ACCESS_MODE=config`, telemetry access can be managed with the
`access/user/*` command family. HTTP login behavior for `access:telemetry` is
not repeated here; see
[`middleware.md#config-backed-access-middleware`](middleware.md#config-backed-access-middleware).

Create a telemetry config user:

```bash
php atomic access/user/create telemetry admin admin
```

Create with an explicit secret and multiple roles:

```bash
php atomic access/user/create telemetry ops admin,support --secret="replace-me"
```

Reset a secret:

```bash
php atomic access/user/reset telemetry admin
```

List stored users:

```bash
php atomic access/user/list
```

Command parameter reference:

- `access/user/create <guard> <username> [roles]` creates or updates a config-backed user record.
- `<guard>` selects which access guard bucket receives the user (for telemetry use `telemetry`).
- `<username>` is normalized to lowercase and used both as the login value and storage key.
- `[roles]` accepts comma-separated role slugs. For telemetry, at least one role must match `TELEMETRY_ACCESS_ALLOWED_ROLES` or `telemetry.access_allowed_roles`.
- `--role=<slug>` or `--roles=<slug1,slug2>` can be used instead of or alongside `[roles]`; all role inputs are merged and deduplicated.
- `--secret=<plain-text-secret>` sets an explicit secret; if omitted, a random 64-hex-character secret is generated.
- `--force` allows overwrite when the user already exists for that guard.
- `access/user/reset <guard> <username> [--secret=...]` updates only the stored `secret_hash` for an existing user.
- `access/user/list` prints all configured guards/users and their roles from the config store.

Framework structures used by these commands (CLI and persistence only):

- `engine/Atomic/CLI/Access.php` parses positionals/options, normalizes names, derives roles, and dispatches create/reset/list behavior.
- `engine/Atomic/Auth/ConfigUserStore.php` persists data to `storage/framework/access_users.php` under `guards.<guard>.users.<username>`.
- `engine/Atomic/Core/Hash.php` hashes secrets before storage (`secret_hash`); cleartext secrets are shown once and never persisted.
- `engine/Atomic/Core/ID.php` generates stable per-user auth IDs (`id`) on create.

At request time, records are loaded into `ACCESS.guards.<guard>.users`, resolved
by `ConfigUserProvider`, and enforced with `access:<guard>` and `role:<slug>`
middleware; see
[`middleware.md#config-backed-access-middleware`](middleware.md#config-backed-access-middleware)
and
[`middleware.md#built-in-role-middleware`](middleware.md#built-in-role-middleware).

Config shape written by `access/user/create`:

```php
<?php
return [
    'guards' => [
        'telemetry' => [
            'users' => [
                'viewer' => [
                    'id' => 'uuid-v4',
                    'username' => 'viewer',
                    'secret_hash' => 'hashed-secret',
                    'roles' => ['admin'],
                ],
            ],
        ],
    ],
];
```

### What it provides

**Queue view** - a paginated, filterable list of all queue jobs across drivers (database, Redis). Jobs can be filtered by status (pending, running, completed, failed), UUID, queue name, and time range. The header shows global status totals that remain accurate regardless of which filter is active.

**Log viewer** - paginated log output for any configured log channel. Supports channel switching, per-day file selection, pagination, and live stat polling (line count, last modified). Log lines with an attached dump can be expanded inline.

**Dump viewer** - opens a structured JSON dump by its UUID, as written by `Log::dump()`. Useful for inspecting large payloads that would be impractical to inline in a log message.

**Dashboard** - a snapshot of the current runtime environment: PHP version and configuration, framework version, debug settings, database connection info, and system details.

**Hive inspector** - a sanitized view of the current F3 hive state. Useful for inspecting what is in scope at request time without access to a debugger.

### How it works

The telemetry panel is a theme (`Telemetry`) that boots alongside the controller. All data is fetched via JSON endpoints and rendered client-side - the queue and log views update without full page reloads. Queue filter changes update the URL and trigger a server-side re-render of the job list, keeping the browser URL shareable and the back button functional.

Queue status totals in the header are always computed against the full dataset, not just the filtered view. Applying a status filter scopes the job list but never affects the global counts.

Log pages are cached based on file modification time. Unchanged pages are served from cache; a changed file invalidates cached pages for that channel.

### Routes

| Route | Purpose |
|---|---|
| `GET /telemetry` | Queue view |
| `POST /telemetry` | Reserved POST endpoint |
| `GET\|POST /telemetry/events/@driver/@job_uuid` | Job event timeline |
| `GET\|POST /telemetry/dashboard` | Runtime environment snapshot |
| `GET\|POST /telemetry/hive` | Sanitized hive dump |
| `GET\|POST /telemetry/logs` | Paginated log output (`channel`, optional `date=YYYY-MM-DD`) |
| `GET\|POST /telemetry/log-channels` | List of configured log channels and available dates per channel |
| `GET\|POST /telemetry/log-stat` | Line count and mtime for a channel/date |
| `GET\|POST /telemetry/dumps/@dump_id` | Retrieve a JSON dump by UUID |

### Usage notes

- Telemetry access roles are enforced only when `TELEMETRY_ACCESS_MODE` is `config` or `auth`.
- `TELEMETRY_ACCESS_ALLOWED_ROLES` and `telemetry.access_allowed_roles` are whitelists; any matching user role grants access.
- `LOGS` must be writable for the log viewer to function.
- Telemetry output is diagnostic - it reflects live runtime state and should not be used as an application data source.
