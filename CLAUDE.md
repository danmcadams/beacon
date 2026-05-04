# Beacon

Local-only personal dashboard. Cron-generated AI briefings, Jira ticket cache, weekly schedule, projects with quick links.

Planning docs live at `~/projects/gibson/docs/beacon/` — see `tracker.md` (status), `overview.md` (what + why), `data-model.md` (schema), `pages.md` (page inventory).

Visual design reference: `~/projects/beacon_design_handoff/`. The HTML/JSX is **reference only** — port to Twig + htmx. `tokens.css` is canonical and copied verbatim.

---

## Stack

- **PHP 8.3+** with `declare(strict_types=1);` in every file
- **Symfony 7.4**
- **Doctrine ORM 3.x** with attribute mapping
- **SQLite** (single file, no separate DB container)
- **Twig** server-rendered + **htmx** for in-page swaps
- **AssetMapper** for CSS + JetBrains Mono font (no Webpack/Vite)
- **PHPUnit 11+** with PHPUnit attributes
- Docker-compose v1 (per host setup)

---

## PHP conventions

- `declare(strict_types=1);` at the top of every file. No exceptions.
- Constructor property promotion. Always.
- `readonly` for any property that doesn't change after construction.
- `final class` by default. Mark non-final only when extension is genuinely intended.
- `enum` for fixed value sets (status, block type) — backed enums with string values for DB storage.
- Typed properties everywhere. Never `mixed` unless the value really is `mixed`.
- Throw typed exceptions. No bare `\Exception` or `\RuntimeException` in domain code.
- No comments explaining *what* code does. Reserve comments for *why* — non-obvious constraints or workarounds.

---

## Symfony conventions

- **Routing:** `#[Route(...)]` attributes on controller methods. Never YAML, never XML, never PHP config.
- **Controllers:** thin. Extract domain logic to services in `App\Service\`.
- **Services:** rely on autowiring + autoconfigure. Don't add entries to `services.yaml` unless something actually requires explicit wiring.
- **Argument resolvers / parameter conversion:** prefer `#[MapEntity]` and `#[MapRequestPayload]` over manual lookup.
- **Configuration:** PHP config (`config/packages/*.php`) is fine when it works; YAML is fine for short config. Don't mix in one file.
- **Console commands:** `#[AsCommand(...)]` attribute, extend `Command`. Idempotent where the operation could re-run (e.g. briefing generation).
- **Forms:** use Form Types only when the form has multiple fields, validation rules, or is reused. For small one-off forms, plain HTML + a request DTO with `#[MapRequestPayload]` is fine.
- **Validation:** attributes on DTOs / entities (`#[Assert\NotBlank]`, etc.). Run via the validator service.

---

## Doctrine conventions

- All entities live in `App\Entity\`.
- Mapping via `#[ORM\Entity]`, `#[ORM\Column]`, `#[ORM\ManyToOne]` etc. — never annotations, never XML/YAML.
- Repositories in `App\Repository\` extending `ServiceEntityRepository`. Inject them via constructor.
- Migrations are the source of truth for schema. Run `make:migration` after entity changes; review the generated SQL before applying.
- SQLite quirks: no `ALTER COLUMN` — Doctrine handles this via table recreation in migrations. Don't hand-edit unless the generated migration is wrong.
- JSON columns: `#[ORM\Column(type: Types::JSON)]`. Doctrine handles serialize/deserialize.
- Dates: `\DateTimeImmutable`, never `\DateTime`.
- IDs: integer auto-increment for internal tables; we don't need ULIDs for a single-user app.

---

## Twig conventions

- Layout extends from `templates/base.html.twig`.
- Page templates extend a section layout (`templates/_layouts/`) when there's a shared structure (e.g. project pages).
- Partials live in `templates/_partials/` with names like `_briefing-card.html.twig`. Leading underscore signals partial.
- htmx swap targets return **partial templates**, not JSON. The same partial is used for first render and re-render.
- Blocks: `{% block title %}`, `{% block main %}`, `{% block aside %}` (left rail). Don't invent new top-level blocks unless the layout needs it.
- Avoid logic in templates beyond rendering. Compute display values in the controller or a Twig extension.

---

## htmx conventions

- Server returns Twig fragment HTML — never JSON.
- Use `hx-target` + `hx-swap="outerHTML"` (or `innerHTML` where appropriate) — be explicit about swap mode.
- Loading states: `hx-indicator` + a CSS class on the target during the request.
- Out-of-band swaps (`hx-swap-oob`) only when truly needed; prefer composing the response on the server.
- For form submissions, use `hx-post` with `hx-target="this"` to swap the form with success/error state.

---

## Testing conventions

- PHPUnit 11+ with attribute syntax: `#[Test]`, `#[DataProvider]`, `#[Group]`.
- Integration tests use `KernelTestCase` / `WebTestCase` with the SQLite test DB.
- No mocking the database. Use real fixtures.
- One test class per service / controller. Test method names describe behavior in plain English.

---

## Things we explicitly DON'T do

- No annotations (`@ORM\Entity`) — attributes only.
- No YAML or XML for routing or Doctrine mapping.
- No services.yaml entries unless autowiring genuinely can't resolve it.
- No JSON responses for htmx interactions — always Twig fragments.
- No JavaScript bundling pipeline. AssetMapper covers CSS + the htmx script. If we need a tiny bit of vanilla JS, it goes in `assets/`.
- No Docker Compose v2 syntax (`docker compose`). v1 (`docker-compose`) until the host migration happens.
- No mocking databases in tests.
- No "TODO" comments without an owner and date — if it's not worth fixing now, it's not worth a comment.

---

## When in doubt

If a Symfony 7.4-specific pattern is ambiguous, fetch the version-pinned docs from `https://symfony.com/doc/7.4/<topic>.html` rather than guessing. **Do not use `/doc/current/`** — that points to Symfony 8, which we are not on. My (Claude's) Symfony knowledge is solid through ~2024; verify recent changes before producing patterns confidently.
