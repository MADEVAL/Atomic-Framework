# Atomic Application

*Application skeleton for Atomic Framework — start building in seconds*

[![PHP](https://img.shields.io/badge/php-%3E%3D8.1-777BB4?logo=php&logoColor=white)](#)
[![Version](https://img.shields.io/github/v/tag/MADEVAL/Atomic-Framework-Application?label=version&color=blue)](https://github.com/MADEVAL/Atomic-Framework-Application/tags)
[![License](https://img.shields.io/badge/license-GPL--3.0--or--later-green.svg)](LICENSE)
[![Packagist](https://img.shields.io/packagist/v/globus-studio/atomic-framework-application?label=packagist&color=orange)](https://packagist.org/packages/globus-studio/atomic-framework-application)
[![Downloads](https://img.shields.io/packagist/dt/globus-studio/atomic-framework-application?color=blue)](https://packagist.org/packages/globus-studio/atomic-framework-application)

> **Packagist:** [`globus-studio/atomic-framework-application`](https://packagist.org/packages/globus-studio/atomic-framework-application) · `composer create-project globus-studio/atomic-framework-application myapp`

---

## Quick start

```bash
composer create-project globus-studio/atomic-framework-application myapp
cd myapp
cp .env.example .env
php atomic init/key
php -S localhost:8000 -t public
```

Or clone directly:

```bash
git clone https://github.com/MADEVAL/Atomic-Framework-Application.git myapp
cd myapp
composer install
cp .env.example .env
php atomic init/key
php -S localhost:8000 -t public
```

Open `http://localhost:8000` — you should see the Atomic welcome page.

---

## What's included

| Directory | Purpose |
|-----------|---------|
| `app/` | Application code — controllers, models, middleware, auth provider |
| `bootstrap/` | App initialization — constants, config loader, error handling |
| `config/` | Configuration files — database, cache, mail, auth, queue |
| `routes/` | Route definitions — `web.php`, `api.php`, `cli.php` |
| `public/` | Web root — entry point, themes, uploads |
| `database/` | Migrations and seeds |
| `resources/` | View templates |
| `storage/` | Logs, cache, sessions |

**Bundled examples:**

| File | Description |
|------|-------------|
| `app/Http/Controllers/HomeController.php` | Landing page controller |
| `app/Http/Controllers/DashboardController.php` | Protected dashboard (requires auth) |
| `app/Http/Controllers/AuthController.php` | Login, register, logout |
| `app/Http/Middleware/Authenticate.php` | Auth guard middleware |
| `app/Http/Models/User.php` | User model (uuid, email, password, role) |
| `app/Auth/UserProvider.php` | Database-backed user provider |
| `database/migrations/create_users_table.php` | Users table migration |

---

## Requirements

| Requirement | Notes |
|-------------|-------|
| PHP ≥ 8.1 | Required extensions: `json`, `session`, `mbstring`, `fileinfo`, `pdo`, `pdo_mysql`, `curl` |
| MySQL / MariaDB | Primary database |
| Redis | Recommended — cache, queues, sessions, WebSockets |
| Composer | Dependency manager |

---

## Common commands

```bash
php atomic init                  # Scaffold project structure
php atomic init/key              # Generate app encryption keys
php atomic migrations/migrate    # Run database migrations
php atomic queue/worker          # Start queue worker
php atomic schedule/run          # Execute due tasks
php atomic routes                # List all registered routes
```

---

## Links

| Resource | URL |
|----------|-----|
| Framework | [globus-studio/atomic-framework](https://packagist.org/packages/globus-studio/atomic-framework) |
| Framework repo | [MADEVAL/Atomic-Framework](https://github.com/MADEVAL/Atomic-Framework) |
| Skeleton repo | [MADEVAL/Atomic-Framework-Application](https://github.com/MADEVAL/Atomic-Framework-Application) |
| Documentation | [docs/](https://github.com/MADEVAL/Atomic-Framework/tree/main/docs) |

---

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
