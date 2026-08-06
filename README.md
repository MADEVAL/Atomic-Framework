# Atomic Framework — Monorepo

A modular PHP framework built on **Fat-Free Framework (F3)**.

This is the **development monorepo** containing both packages:

| Package | Directory | Packagist |
|---------|-----------|-----------|
| Framework | `packages/framework/` | [globus-studio/atomic-framework](https://packagist.org/packages/globus-studio/atomic-framework) |
| Skeleton | `packages/skeleton/` | [globus-studio/atomic-framework-application](https://packagist.org/packages/globus-studio/atomic-framework-application) |

## Quick Start

```bash
git clone https://github.com/MADEVAL/Atomic-Framework.git
cd Atomic-Framework
composer install
composer test
```

## Development

```bash
# Run framework unit tests
composer test-fw

# Run integration tests
composer test-integration

# Run all tests
composer test
```

## Publishing

On release tag, GitHub Actions auto-splits each package to its own repository:
- `packages/framework/` → [MADEVAL/Atomic-Framework](https://github.com/MADEVAL/Atomic-Framework)
- `packages/skeleton/` → [MADEVAL/Atomic-Framework-Application](https://github.com/MADEVAL/Atomic-Framework-Application)

Packagist auto-updates from those repos.

## Documentation

Full documentation is in `packages/framework/docs/`. Framework README is at `packages/framework/README.md`.

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
