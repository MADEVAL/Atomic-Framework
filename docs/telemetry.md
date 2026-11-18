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

Thirteen tabs, each also available as a JSON endpoint:

- **Dashboard** - what needs attention (failed jobs, pending migrations, limited keys, locks) and the runtime environment: PHP, framework, database and system.
- **Queues** - today's jobs per queue and hour, the job list with state filters, and each job's payload, error, stack trace and event timeline.
- **Scheduled** - scheduled tasks with their recent runs, durations and failures.
- **Migrations** - applied and pending migrations by batch, integrity problems, and each migration's source file.
- **Quota**, **Exceptions**, **Security** - history over the last 24 hours, 7 days or 30 days (the range bar): credit balances and denials; exceptions grouped by fingerprint with trends and the hive dump of an occurrence; failed logins, lockouts, active sessions and the security policy.
- **Rate limits**, **Plugins**, **Mutex** - keys that were blocked, plugin load state and dependencies, held and expired locks.
- **Database** - the read-only schema of the default connection: tables, columns, indexes and foreign keys, with a relation diagram. No row data is read.
- **Hive** - the F3 hive of the request as a tree, with secrets masked.
- **Logs** - every channel or one, by day, filtered by level and text. Older lines load as you scroll; lines with a hive dump open it inline.

### How it works

The panel is a theme (`Telemetry`) rendered on the server. `GET /telemetry` returns the whole page with the tab named by `?tab=` already rendered; `GET /telemetry/view/@tab` returns one tab, and `GET /telemetry/part/@part` returns a piece loaded on demand (a migration file, a job timeline, a hive dump, older log lines).

Each tab is a typed template in `views/<tab>.php`: a closure that takes the tab's data objects (`Engine\Atomic\Telemetry\Data`) and composes components from `Engine\Atomic\Telemetry\View\Ui`. Every text is escaped on output. A custom theme can override any template.

One script, `assets/js/server.js`, swaps tab content in place (prefetching a tab while the pointer rests on its button), filters and searches the rendered rows, opens drawers and popups, and runs refresh, auto refresh, the range bar and the theme switch. It never builds markup from strings.

Secrets are masked twice: reporters redact their data like the JSON endpoints do, and every text the templates print passes through `Redactor::redact_secrets()`. Migration source files are shown as written.

Queue state totals are always computed against the full dataset, not just the loaded page. Log pages are cached based on file modification time; a changed file invalidates the cached pages of that channel.

### Routes

| Route | Purpose |
|---|---|
| `GET /telemetry` | The panel (`?tab=` opens a tab) |
| `POST /telemetry` | Reserved POST endpoint |
| `GET /telemetry/view/@tab` | One tab's HTML (`?range=24h\|7d\|30d` for history tabs, `?channel=`, `?date=` for Logs) |
| `GET /telemetry/part/@part` | HTML loaded on demand: `job-events`, `migration-source`, `dump`, `log-lines` |
| `GET\|POST /telemetry/summary` | Counts behind the navigation badges |
| `GET\|POST /telemetry/jobs` | Newest queue jobs |
| `GET\|POST /telemetry/events/@driver/@job_uuid` | Job event timeline |
| `GET\|POST /telemetry/schedule` | Scheduled tasks and run history |
| `GET\|POST /telemetry/migrations` | Migrations by batch |
| `GET /telemetry/migration-source` | A migration's source file |
| `GET\|POST /telemetry/quota`, `/exceptions`, `/security` | History tabs (`?range=`) |
| `GET\|POST /telemetry/rate-limits`, `/plugins`, `/mutex` | Current state |
| `GET /telemetry/database` | Database schema |
| `GET\|POST /telemetry/dashboard` | Runtime environment snapshot |
| `GET\|POST /telemetry/hive` | Sanitized hive dump |
| `GET\|POST /telemetry/logs` | Paginated log output (`channel`, optional `date=YYYY-MM-DD`) |
| `GET\|POST /telemetry/log-channels` | Configured log channels with line counts |
| `GET\|POST /telemetry/log-stat` | Line count and mtime for a channel/date |
| `GET\|POST /telemetry/dumps/@dump_id` | Retrieve a JSON dump by UUID |

### Usage notes

- Telemetry access roles are enforced only when `TELEMETRY_ACCESS_MODE` is `config` or `auth`.
- `TELEMETRY_ACCESS_ALLOWED_ROLES` and `telemetry.access_allowed_roles` are whitelists; any matching user role grants access.
- `LOGS` must be writable for the log viewer to function.
- Telemetry output is diagnostic - it reflects live runtime state and should not be used as an application data source.
