# CLAUDE.md

Sylius 2 plugin adding an order / customer timeline and internal staff notes to the admin.
Functional spec: `SPEC.md`. Public docs: `README.md`.

## Commands

PHP needs more than 128 MB to compile the test application container: prefix console and
PHPStan calls with `php -d memory_limit=2G` when running locally.

```bash
docker compose up -d mysql mailhog        # ports come from compose.override.yml (gitignored)
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite non-unit   # functional + integration, need the test DB + Sylius fixtures
vendor/bin/phpstan analyse                # level max, src + tests
vendor/bin/ecs check
vendor/bin/console lint:container
vendor/bin/console tmonier:timeline:backfill --dry-run
```

Local env overrides (gitignored): `compose.override.yml` (MySQL published on 3316, MailHog on
8035/1035), `tests/TestApplication/.env.local` (DATABASE_URL + MAILER_DSN) and
`.env.test.local` (DATABASE_URL). Databases: `tmonier_sylius_timeline_dev` / `_test`.

## Architecture

- `src/Entity/TimelineEntry` — single resource for every entry (workflow events, e-mails,
  notes). XML mapping in `config/doctrine` (mapped superclass). Order entries also carry the
  order's customer so the customer timeline is one query.
- `src/Recorder/TimelineRecorder` — turns a workflow transition or an e-mail into entries
  (filtering, snapshots in `context`, author via `ActorResolver`).
- `src/Recorder/TimelineEntryWriter` — persistence strategy, keep it:
  - `persist()` never flushes: the entry is saved by the flush of the state change. Entries of
    orders not persisted yet wait in a queue resolved in Doctrine `onFlush` (never
    cascade-persist an order);
  - `save()` (e-mails, sent after the application flush) flushes only when the entry is the
    only pending change of the unit of work.
- `src/EventListener` — `workflow.completed` and `sylius.email_send.post_send` listeners; they
  catch and log every error (a failed recording must never break a checkout).
- `src/Controller/Admin/TimelineNoteController` — CSRF-protected POST actions for notes, and
  the full customer timeline page.
- `src/Twig` — Twig functions used by the hook templates; `TimelineEntryPresenter` gives the
  label (translation `tmonier_timeline.event.<workflow>.<transition>`, generic fallback),
  icon, colour and author.
- `src/Backfill` + `src/Command/BackfillTimelineCommand` — rebuild entries for orders without any.
- `config/grids/order.yaml` adds the notes field to `sylius_admin_order`.

## Rules

- Recorded workflows must go through Symfony Workflow (`symfony_workflow` adapter).
- Notes are rendered with `|nl2br` on autoescaped content: never `|raw`.
- Hook context values may be missing when null: use `hookable_metadata.context.x|default(null)`.
- Model class parameters `%tmonier_timeline.model.*.class%` are set in `prepend()` because
  grids are processed before the extension loads.
- Migrations use the Schema API (MySQL, MariaDB, PostgreSQL) and contain plugin tables only.
- Every translation key must exist in en, fr, de, es, it, nl, pl (`TranslationsTest`), and every
  recorded Sylius transition needs a label. Clear the cache (dev and test) after adding a
  translation file.
- Functional tests run in a transaction rolled back after each test (kernel not rebooted):
  they can cancel or ship fixture orders freely.
