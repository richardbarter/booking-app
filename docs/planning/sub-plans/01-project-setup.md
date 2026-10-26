# 01 — Project setup

## Goal

A fresh Laravel 13 app from the official Vue starter kit, running locally in Docker with PostgreSQL. Tests run against PostgreSQL, Laravel Boost is installed for AI-assisted work, and `CLAUDE.md` points every future session at the planning docs.

## Depends on

Nothing. This is the first sub-plan.

## Scope

**In scope**
- Installing Laravel 13 with the Vue starter kit, and initialising git
- Docker for local development: `app`, `vite`, `postgres`, `mailpit`
- A production-ready multi-stage `Dockerfile` (used locally now, deployed in 02)
- PHPUnit running on a PostgreSQL `testing` database
- Vitest set up with one example test
- Laravel Boost and MCP configuration
- `CLAUDE.md`
- A short README

**Out of scope**
- GitHub repo, CI, Fly.io and Neon (02)
- Any tables beyond the starter kit's own (03)
- Playwright (08)
- `scheduler`, `queue` and `reverb` containers (08, 12 and 17)
- Security headers and a Content Security Policy (sub-plan 02 decides when)

## What the starter kit ships with

Checked against `laravel/vue-starter-kit` in October 2026; confirm when installing.

| Area | Included |
|---|---|
| Backend | Laravel 13 (PHP ^8.3), Inertia 3, Fortify for auth (including passkeys), Wayfinder for typed routes |
| Backend dev tools | PHPUnit 12, Pint, Larastan (PHPStan), Pail, Sail |
| Frontend | Vue 3, TypeScript, Tailwind CSS 4, reka-ui and shadcn-vue style components, `@lucide/vue`, `vue-sonner` |
| Tooling | **Vite+** (`vp`): `vp dev`, `vp build`, `vp check` (lint and format with Oxlint and Oxfmt); `vue-tsc` for type checks |
| Composer scripts | `composer test` (Pint check, PHPStan, tests), `composer lint`, `composer ci:check`, `composer dev` (`php artisan dev`) |

## Data model

No new tables. The starter kit's migrations (users, sessions, cache, jobs, passkeys and so on) run on PostgreSQL.

A second database, `testing`, is created by a PostgreSQL init script. The `btree_gist` extension isn't installed yet; sub-plan 05 adds it in a migration. Here we only check that it's available.

## Steps

This is a setup sub-plan, so ordered steps take the place of the usual Backend and Frontend sections.

### 1. Install into a temporary folder

`D:\projects\booking-app` already contains `docs/`, so the installer can't target it. **Never use the installer's `--force` flag: it deletes the target folder, `docs/` included.**

```bash
cd /d/projects
laravel new booking-app-install --vue --phpunit --database=pgsql --npm
```

- Choose Laravel's built-in authentication, not WorkOS.
- If the installer offers to run migrations and fails because PostgreSQL isn't running yet, that's fine. Migrations run in Docker later.
- Boost is installed separately in step 6, because its installer asks interactive questions.

Then move everything, including dotfiles, into the project folder:

```bash
cd /d/projects
shopt -s dotglob
mv booking-app-install/* booking-app/
rmdir booking-app-install
```

### 2. Git

1. In `D:\projects\booking-app`, run `git init -b main`.
2. Check `.gitignore` covers `.env`, `vendor`, `node_modules`, `public/build`, `public/hot` and generated Wayfinder files.
3. Check `.gitattributes` forces LF line endings for shell scripts (`*.sh text eol=lf`). A CRLF `entrypoint.sh` breaks the Linux container with "bad interpreter".
4. Make a first commit: the untouched starter kit plus `docs/`.
5. Do the rest of this sub-plan on `feature/01-project-setup`, then merge it into `main` locally. There's no remote until sub-plan 02.

### 3. Docker

**Images:** use the default Debian-based official images, not Alpine, anywhere Node runs. The starter kit pins native binaries for `linux-x64-gnu` (glibc), and Alpine uses musl. Use PHP 8.4 to match the host PHP that Boost and the editor tools use.

**Wayfinder needs PHP wherever Vite runs.** Its Vite plugin runs `php artisan wayfinder:generate` during both dev and build. So the image that runs Vite, and the stage that builds the production assets, need both PHP and Node.

**`Dockerfile`** (multi-stage)

| Stage | Contents |
|---|---|
| `composer-deps` | `composer:2`; `composer install --no-dev --no-scripts --no-autoloader --prefer-dist` |
| `assets` | PHP 8.4 CLI + Node 22 + `vendor` from `composer-deps`; `npm ci && npm run build`, which produces `public/build`. Wayfinder runs here. |
| `php` (production) | `php:8.4-fpm` + Nginx + Supervisor (see below) |
| `dev` | Built from `php`. Adds Node 22 and dev dependencies (`composer install` with dev packages). Used by the `app` and `vite` services. |

The `php` stage should include:
- extensions `pdo_pgsql`, `opcache`, `pcntl`, `bcmath`, `intl`, `gd`, `exif` and `zip`
- the production `php.ini`, plus OPcache tuning and upload limits
- the app source, `vendor` and `public/build`
- writable `storage` and `bootstrap/cache`
- `composer dump-autoload --optimize`
- port 8080

**`docker/` files**

| File | Purpose |
|---|---|
| `nginx.conf` | Listens on 8080 with root `public/`. Uses the standard Laravel `try_files`, passes PHP to `127.0.0.1:9000`, hides `X-Powered-By`, denies dotfiles except `.well-known`, gzip on, temp paths writable by `www-data`. Security headers stay in Laravel, not Nginx. |
| `supervisord.conf` | Runs `php-fpm -F` and `nginx -g "daemon off;"`, logging to stdout/stderr |
| `entrypoint.sh` | See below |
| `php-dev.ini` | Turns on `display_errors` and turns off OPcache, so code changes show up immediately |
| `postgres/init.sql` | `CREATE DATABASE testing;` |

`entrypoint.sh`:
1. Runs `php artisan package:discover`.
2. Outside local, runs `config:cache`, then `route:cache` and `view:cache`. This happens at runtime, not build time, so Fly secrets are available.
3. If a command was passed (Fly's `release_command` in sub-plan 02), runs it with `exec "$@"` and exits.
4. Runs `storage:link`.
5. In local only, runs `migrate --force`.
6. Starts `supervisord` with `exec`.

**`.dockerignore`:** `.git`, `node_modules`, `vendor`, `public/build`, `public/hot`, storage caches and logs, `.env*` (except `.env.example`), `tests`, `docs`, `docker-compose.yml` and `*.md`.

**`docker-compose.yml`**

The ports are chosen so they don't clash with other local projects.

| Service | Image or build | Ports (host → container) | Notes |
|---|---|---|---|
| `postgres` | `postgres:<major>` (see Notes) | `127.0.0.1:5434 → 5432` | DB `booking`; named volume; `docker/postgres/init.sql` mounted into `/docker-entrypoint-initdb.d/`; healthcheck with `pg_isready` |
| `app` | build, target `dev` | `127.0.0.1:8001 → 8080` | Mounts the code. Keeps `vendor`, `node_modules` and `bootstrap/cache` in container volumes. Mounts `php-dev.ini`. Sets `APP_ENV=local`, `APP_DEBUG=true`, `DB_HOST=postgres`, `DB_PORT=5432`, `MAIL_HOST=mailpit`, `MAIL_PORT=1025`. Waits for a healthy `postgres`. |
| `vite` | same `dev` image | `127.0.0.1:5174 → 5174` | Same mounts. Runs `npm run dev` on host `0.0.0.0`, port 5174. `WATCH_USE_POLLING=true`. |
| `mailpit` | `axllent/mailpit` | `127.0.0.1:8026 → 8025` (UI), `127.0.0.1:1026 → 1025` (SMTP) | Catches all outgoing email |

**`vite.config.ts` changes for Docker** (keep all of the starter kit's plugins and settings):
- set the dev server port to 5174 with `strictPort`
- set the HMR host to `localhost`
- poll for file changes when `WATCH_USE_POLLING` is `true`, because Docker Desktop on Windows doesn't pass file-change events through
- don't watch `vendor` or `storage`

**`.env.example` and `.env`**, for running things on the host, such as Boost's MCP server and `php artisan test`:
- `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=5434`, `DB_DATABASE=booking`, `DB_USERNAME=postgres`, `DB_PASSWORD=password`
- `MAIL_MAILER=smtp`, `MAIL_HOST=127.0.0.1`, `MAIL_PORT=1026`
- `APP_URL=http://localhost:8001`
- `APP_FAKER_LOCALE=en_GB`

Docker Compose overrides the host and port values for containers.

### 4. Database and tests

- In `phpunit.xml`, replace the SQLite settings with `DB_CONNECTION=pgsql` and `DB_DATABASE=testing`, both with `force="true"`. Without `force`, the container's `DB_DATABASE=booking` environment variable would win, and `RefreshDatabase` would wipe the development database.
- Don't set the host or port in `phpunit.xml`. They come from the environment, so tests work both in the container and on the host.
- Add `tests/Feature/DatabaseConnectionTest.php`. It checks that the driver is `pgsql` and the database is `testing`, which guards against the mistake above.
- Check `btree_gist` is available: `SELECT name FROM pg_available_extensions WHERE name = 'btree_gist';`

### 5. Frontend tooling

- Use Vite+'s built-in test runner (`vp test`, which runs Vitest):
  - add a `test` block to `vite.config.ts` with a DOM environment (jsdom or happy-dom)
  - install `@vue/test-utils`
  - add a `"test": "vp test"` script
  - write one example component test
- If `vp test` turns out not to suit, install Vitest directly instead, and note why in `tracking.md`.
- Check that `npm run check` and `npm run types:check` pass.

### 6. Laravel Boost and other AI tools

1. Run `composer require laravel/boost --dev`.
2. Run `php artisan boost:install` in a terminal. It's interactive, so the user runs it with `! php artisan boost:install` from Claude Code. Choose **Claude Code**, and turn on **guidelines, skills and MCP**.
   - Expected guidelines include Laravel 13, Inertia Vue 3.x, Tailwind 4, Wayfinder, PHPUnit and Pint.
   - Expected skills include `inertia-vue-development`, `tailwindcss-development`, `wayfinder-development` and `infer-conventions`.
3. Add `@php artisan boost:update --ansi` to Composer's `post-update-cmd`.
4. `.mcp.json` should contain:
   - **`laravel-boost`** (`php artisan boost:mcp`). It runs on the host's PHP, which has `pdo_pgsql`, and reaches the Docker database on `127.0.0.1:5434`.
   - **`shadcnVue`** (on Windows: `cmd /c npx shadcn-vue@latest mcp`).
5. Commit `CLAUDE.md`, `boost.json`, `.mcp.json` and Boost's skill files. None of them contain secrets.
6. Boost's **project rules** (`.ai/rules`, recorded with its `record-rule` tool) will hold rules that can't be worked out from the code. The first ones come in sub-plan 03: tenant scoping, and scheduling code receiving the business explicitly.

### 7. Project basics

- Set `APP_NAME` to "Booking App". It's a placeholder until the name is chosen in sub-plan 02.
- Leave the app time zone as UTC. Each business's own time zone is handled in code (03 and 04).
- `composer dev` (`php artisan dev`) is left in place, but Docker is the main way to run the app.

### 8. `CLAUDE.md`

Write the project's own sections above Boost's `<laravel-boost-guidelines>` block, which Boost manages itself:

- **About:** one paragraph describing the app. Tell the reader to read `docs/planning/00-overview.md` and `docs/planning/tracking.md` before starting any work. Sub-plans are in `docs/planning/sub-plans/`, and background is in `docs/background.md`.
- **Commands:**
  - `docker compose up -d`
  - `docker compose exec app php artisan test --compact` (or `php artisan test` on the host)
  - `docker compose exec app php artisan ...`
  - `vendor/bin/pint --dirty`
  - `composer types:check`
  - `npm run check`, `npm run types:check` and `npm run test`
  - after changing dependencies, also run `composer install` or `npm install` inside the container
- **Conventions:**
  - Controllers follow Authorize, Validate, Act, Respond.
  - Form Requests for validation; policies for authorisation.
  - Thin controllers, with logic in service and action classes.
  - Eloquent scopes; route model binding; enums for statuses and roles.
  - Vue: props down, events up, and never mutate props. Reusable logic goes in composables.
  - Order inside `<script setup>`: imports, types, constants, reactive state, functions, watchers, lifecycle hooks, side effects.
  - Use Pinia only when props, events and Inertia props aren't enough.
  - shadcn-vue components are thin wrappers around reka-ui. When one misbehaves, check the reka-ui docs.
- **Key project rules** (from overview section 6):
  - PostgreSQL only, never SQLite.
  - Store times in UTC.
  - Scheduling code receives the business it works on, rather than reading it from the logged-in user.
  - Every tenant-owned table has a `business_id`.
- **Workflow:** one sub-plan per branch and pull request, then update `tracking.md`. The repo is public, so docs stay about the product.

### 9. README

Keep it short for now:
- one paragraph on what the app is
- status: in development
- how to run it locally with Docker
- links to `docs/background.md` and `docs/planning/`

The full README with screenshots comes in sub-plans 08 and 15.

## Tests

- The starter kit's own tests (auth, settings and so on) pass on the PostgreSQL `testing` database, both in the container and on the host.
- `DatabaseConnectionTest` confirms the driver is `pgsql` and the database is `testing`.
- One example Vitest component test passes.
- These checks pass: Pint, Larastan (`composer types:check`), `npm run check` and `npm run types:check`.

## Done when

- [ ] After `cp .env.example .env` and `php artisan key:generate`, `docker compose up -d` starts `postgres`, `app`, `vite` and `mailpit`
- [ ] http://localhost:8001 shows the starter kit; registering and logging in work; editing a Vue file updates the page through HMR
- [ ] Emails such as password resets appear in Mailpit at http://localhost:8026
- [ ] `docker compose exec app php artisan test` passes on the `testing` database
- [ ] `php artisan test` on the host passes too
- [ ] `btree_gist` shows as available in the local PostgreSQL
- [ ] `docker build --target php .` builds the production image
- [ ] Boost's MCP server connects in Claude Code, and its docs search and database schema tools work
- [ ] `CLAUDE.md` and the README stub are written
- [ ] `docker/entrypoint.sh` has LF line endings
- [ ] Everything is committed, `feature/01-project-setup` is merged into `main`, and `tracking.md` is updated

## Notes

- **PostgreSQL version:** use the major version Neon gives new projects (check Neon's docs), so local, CI and production match. Sub-plan 02 confirms it on Neon itself.
- **Init scripts only run once,** when the PostgreSQL volume is first created. If the `testing` database is missing, create it by hand, or run `docker compose down -v`, which deletes all local data.
- **Bind mounts on Windows are slow.** That's why `vendor` and `node_modules` live in container volumes. After `composer require` or `npm install` on the host, run the same command in the container, or rebuild.
- **Vite+ is new** (it went public in 2026). If `vp test`, `vp check` or the dev server behaves oddly in Docker, record the workaround in `tracking.md`.
- **Inertia 3 docs:** Boost's docs search covers Inertia up to 2.x. For anything specific to Inertia 3, check the official Inertia docs.
- **Herd isn't used** for running the app (Docker is). The host PHP from Herd Lite is still used for Boost's MCP server, editor tooling and optionally running tests.
