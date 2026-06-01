# Beacon

Local-only personal dashboard: cron-generated AI briefings, a Jira ticket cache, a weekly schedule, and projects with quick links.

Beacon is a single-user, self-hosted command center. It runs entirely on your own machine — there's no multi-tenancy, no auth, and no cloud dependency beyond the AI briefing generation.

## Stack

- **PHP 8.3+** / **Symfony 7.4**
- **Doctrine ORM 3.x** with attribute mapping
- **SQLite** — a single file, no separate DB container
- **Twig** server-rendered, **htmx** for in-page swaps
- **AssetMapper** for CSS + fonts (no Webpack/Vite)
- **Anthropic PHP SDK** for briefing generation
- Docker Compose (PHP-FPM + nginx)

## Features

- **AI briefings** — daily briefings generated on a cron schedule and rendered on the home page.
- **Jira cache** — locally cached Jira tickets, refreshed via a console command.
- **Weekly schedule** — schedule blocks with browser notifications for upcoming items.
- **Projects** — projects with quick links, created through a multi-step wizard.

## Getting started

The app runs under Docker Compose (v1):

```bash
docker-compose up -d --build
```

nginx serves the app on **http://localhost:8282**.

Install dependencies and set up the database:

```bash
docker-compose exec php composer install
docker-compose exec php bin/console doctrine:migrations:migrate
```

Load sample data (optional):

```bash
docker-compose exec php bin/console doctrine:fixtures:load
```

## Console commands

| Command | Description |
| --- | --- |
| `app:briefing:generate` | Generate AI briefings (idempotent; intended to run on a cron). |
| `app:jira:refresh` | Refresh the local Jira ticket cache. |

Run them via:

```bash
docker-compose exec php bin/console app:briefing:generate
docker-compose exec php bin/console app:jira:refresh
```

## Database

SQLite lives at `var/data_<env>.db`. Migrations under `migrations/` are the source of truth for the schema — after changing an entity, generate a migration and review the SQL before applying:

```bash
docker-compose exec php bin/console make:migration
docker-compose exec php bin/console doctrine:migrations:migrate
```

## Project layout

```
src/
  Command/      Console commands (briefing generation, Jira refresh)
  Controller/   Thin controllers
  Entity/       Doctrine entities
  Enum/         Backed enums for fixed value sets
  Repository/   Doctrine repositories
  Service/      Domain logic
  Twig/         Twig extensions
  Wizard/       Session-backed multi-step wizard state
templates/      Twig templates (_layouts/, _partials/, feature dirs)
assets/         CSS + vanilla JS (AssetMapper)
migrations/     Doctrine migrations (schema source of truth)
```

See `CLAUDE.md` for the full set of project conventions.
