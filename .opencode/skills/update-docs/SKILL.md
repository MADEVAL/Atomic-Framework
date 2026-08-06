---
name: update-docs
description: Documentation update workflow. Use after code changes - new features, refactors, setting additions, hook additions, DB migrations, or file additions/deletions. Updates all affected docs consistently.
---

# Update Docs After Code Changes

## Overview

Code without matching docs is a lie waiting to bite the next developer. Every code change must be reflected in docs.

**Core principle:** `git diff` tells you what changed. The doc map tells you where to write.

**Violating the letter of this process is violating the spirit of documentation.**

## The Iron Law

```
NO CODE CHANGE WITHOUT DOC UPDATE
```

If you committed code without updating docs, update them now.

## When to Use

Use after ANY code change in the monorepo: framework code (`engine/Atomic/`) or skeleton code (`packages/skeleton/`). Bug fixes that don't change interfaces, settings, hooks, or architecture may skip - but when in doubt, update.

## The Doc Map

All docs live under `docs/`. Here is what each file covers and what triggers an update to it:

### Primary Reference Docs (highest priority)

| Doc | Covers | Update When |
|-----|--------|-------------|
| `docs/atomic_core.md` | Bootstrap lifecycle: events, hooks, fluent chain order | Bootstrap logic changes, hook order changes |
| `docs/config.md` | Configuration modes (.env + PHP arrays), all config keys | New/removed/changed config keys |
| `docs/database.md` | DB connections, ConnectionManager, ORM | DB layer changes |
| `docs/model.md` | Base Model, fieldConf, validation rules | Model API changes |
| `docs/migrations.md` | Migration system, CLI commands | Migration system changes |
| `docs/cache.md` | Cache drivers, cascade fallback, Transient | Cache API changes |
| `docs/queue.md` | Queue drivers (Redis/DB), workers, monitor | Queue API changes |
| `docs/scheduler.md` | Cron scheduler, event frequencies | Scheduler API changes |
| `docs/middleware.md` | Middleware stack, named aliases | Middleware API changes |
| `docs/event.md` | Event dispatcher, priorities | Event system changes |
| `docs/hook.md` | WordPress-compatible hooks, actions, filters | Hook system changes |
| `docs/mailer.md` | SMTP mailer, Notifier | Mail API changes |
| `docs/i18n.md` | Internationalization, I18n class | i18n changes |
| `docs/cli.md` | CLI commands, Style, Console | CLI changes |
| `docs/session.md` | Session drivers, SessionManager | Session API changes |
| `docs/theme.md` | Theme manager, assets, OpenGraph | Theme API changes |
| `docs/plugins.md` | Plugin system, PluginManager | Plugin API changes |
| `docs/log.md` | Log channels, levels | Logging changes |
| `docs/security.md` | Crypto, Guard, CSRF, RateLimit | Security changes |
| `docs/testing_guide.md` | Test patterns, conventions | Testing changes |

### Framework Source (`engine/Atomic/`)

| Doc | Covers | Update When |
|-----|--------|-------------|
| `docs/request.md` | Request/Response classes | Request/Response API changes |
| `docs/prefly.md` | Preflight checks | Prefly logic changes |
| `docs/errorhandler.md` | Error handling, exception registrar | Error handling changes |
| `docs/rate_limit.md` | Rate limiting, Redis Lua scripts | Rate limit changes |
| `docs/mutex.md` | Distributed locking | Mutex changes |
| `docs/nonce.md` | Nonce tokens | Nonce changes |
| `docs/transient.md` | Transient storage | Transient API changes |
| `docs/redactor.md` | Sensitive data redaction | Redactor changes |
| `docs/head.md` | HTML head metadata | Head API changes |
| `docs/opengraph.md` | OpenGraph generation | OpenGraph changes |
| `docs/template.md` | Template rendering | Template changes |
| `docs/websockets.md` | WebSocket server | WebSocket changes |
| `docs/telemetry.md` | Telemetry tracking | Telemetry changes |
| `docs/telegram.md` | Telegram bot integration | Telegram changes |
| `docs/ai_connector.md` | AI connector | AI connector changes |
| `docs/applications.md` | Queue application jobs | Job application changes |
| `docs/atomic_methods.md` | Atomic utility methods | Method changes |
| `docs/atomic_pdf.md` | PDF generation | PDF changes |
| `docs/atomic_xls.md` | XLS/OLE2 reading | XLS changes |
| `docs/assets.md` | Asset enqueueing | Asset changes |
| `docs/notifier.md` | Notification system | Notifier changes |

## The Update Process

### Step 1: Identify What Changed

```bash
git diff <start-commit>..<end-commit> --stat
```

Categorize each file change:
- **New file** → document the class/feature/section
- **Modified file** → check which interfaces changed (new methods? new config keys? new hooks?)
- **Deleted file** → remove from docs (rare)

### Step 2: Cross-Reference Against the Doc Map

For each category of change, consult the doc map above. One code change often requires updates to multiple docs.

**Example: Adding a new plugin:**
- `docs/plugins.md` — add plugin entry
- `docs/README.md` — update plugins list

**Example: Adding new config keys:**
- `docs/config.md` — add key description
- `packages/skeleton/.env.example` — add the key

### Step 3: Verify Consistency

After all edits, run a quick sanity check:

```bash
git diff --stat HEAD -- docs/
```

Read through the diffs. Ask:
- Do all cross-references still point to valid sections?
- Are all examples up to date?
- Are version strings current?

## HARD-GATE Rules

1. **AGENTS.md wins.** If a doc contradicts AGENTS.md, the doc is wrong. Fix it.
2. **Never invent docs.** Only document what exists in source. Run `git diff`, not `grep` on source code. Documentation reflects reality.
3. **Cross-references are live links.** If you rename a section, update every link to it.
4. **Keep framework and skeleton in sync.** Changes to framework behavior may need skeleton example updates in `packages/skeleton/`.

## Common Anti-Patterns

| Anti-Pattern | Reality |
|---|---|
| "The code is self-documenting" | No it's not. Write the doc. |
| "I'll update docs later" | Later never comes. Do it now. |
| "It's just a small change" | Small changes still need docs. |

## Quick-Reference: Per-Change-Type Checklist

### New Class or Trait
- [ ] `docs/atomic_core.md` or relevant subsystem doc: add description
- [ ] AGENTS.md: update if it's an app base class

### New Config Key
- [ ] `docs/config.md`: add key description
- [ ] `packages/skeleton/.env.example`: add the key

### New CLI Command
- [ ] `docs/cli.md`: add command entry

### New Hook or Event
- [ ] `docs/hook.md` or `docs/event.md`: add entry

### Bootstrap Logic Change
- [ ] `docs/atomic_core.md`: update lifecycle chain
- [ ] `packages/skeleton/bootstrap/app.php`: update if needed
