# Tracking

Last updated: 2026-10-27

## Current status

Sub-plan 01 is done. The Laravel 13 Vue starter kit runs in Docker (PostgreSQL 18, PHP 8.5, Node 26, Mailpit), tests run on PostgreSQL in the container and on the host, the production image builds, and Laravel Boost is set up for Claude Code and Cursor.

Sub-plan 01b, which moves development into WSL 2, is planned and comes next.

## Next up

1. Do sub-plan 01b: steps 1 to 4 by hand, then step 5 in Claude Code inside Ubuntu.
2. Write the next sub-plans from a Claude Code session opened in this project (in WSL from now on), so Laravel Boost's docs search is available.
3. Choose a product name before sub-plan 02, because it sets the Fly app name and URL.

## Sub-plan status

Status values: Not started, Planned (plan written), In progress, Done.

| # | Sub-plan | Status | PR | Notes |
|---|---|---|---|---|
| | **Phase 1: Foundations** | | | |
| 01 | Project setup | Done | — (merged locally; no remote until 02) | Uses newer versions than the plan; see the decisions log |
| 01b | WSL development environment | Planned | | Added after 01: moves development off the Windows filesystem |
| 02 | CI and deployment | Not started | | |
| 03 | Core data model | Not started | | |
| | **Phase 2: Scheduling engine** | | | |
| 04 | Availability engine | Not started | | |
| 05 | Making bookings | Not started | | |
| 06 | Waste score | Not started | | |
| 07 | Strictness and relaxation | Not started | | |
| | **Phase 3: Playground** | | | |
| 08 | Scheduling playground | Not started | | |
| ★ | **Playground milestone** | | | |
| | **Phase 4: Full product** | | | |
| 09 | Accounts and sign-up | Not started | | |
| 10 | Admin setup | Not started | | |
| 11 | Public booking page | Not started | | |
| 12 | Emails and reminders | Not started | | |
| 13 | Admin calendar | Not started | | |
| 14 | Efficiency score | Not started | | |
| 15 | Demo mode and README | Not started | | |
| ★ | **MVP milestone** | | | |
| | **Phase 5: Extras** | | | |
| 16 | Diary health check (import) | Not started | | |
| 17 | Live updates | Not started | | |
| 18 | Embeddable widget | Not started | | |
| 19 | Learning from history | Not started | | |
| 20 | Simulator | Not started | | |
| 21 | Insights dashboard | Not started | | |
| 22 | Final polish | Not started | | |

## Decisions log

| Date | Decision | Reason |
|---|---|---|
| 2026-10-26 | Laravel + Inertia + Vue 3 + TypeScript + PostgreSQL, following normal Laravel best practice | A well-supported stack with queues, scheduling, auth and websockets built in |
| 2026-10-26 | Docker for local development | Matches production and starts with one command |
| 2026-10-26 | Fly.io (London) + Neon PostgreSQL + Cloudflare R2 | Cheap and proven; London regions because the users are in the UK |
| 2026-10-26 | Multi-tenant from day one | Adding tenancy later would touch every query |
| 2026-10-26 | Tests run on PostgreSQL, not SQLite | The double-booking constraint is PostgreSQL-only |
| 2026-10-26 | Slots calculated on the fly, never stored | Availability always reflects current bookings |
| 2026-10-26 | Customer booking flow uses a stateless JSON API | Has to work inside the embed iframe, where cookies are blocked |
| 2026-10-26 | Embed is a JS loader script that opens an iframe (inline or popup) | Polished on the business's site, isolated from its styles, updates without the business doing anything |
| 2026-10-26 | Scheduling efficiency through a waste score, a strictness slider (0–100) and a relaxation window; history learning and the simulator come after the MVP | One consistent model that a business controls, extended with data later |
| 2026-10-26 | Add a diary efficiency score (sub-plan 14) that measures time lost to gaps separately from quiet time; log availability searches from the public booking page (sub-plan 11) | Shows owners what awkward gaps cost them, without blaming scheduling for quiet weeks; logging early builds up history for later features |
| 2026-10-26 | Add a diary health check (sub-plan 16, first after the MVP): import CSV, Excel or .ics, map the columns, get an efficiency score | A no-setup demo of the analysis that gives businesses a reason to sign up; imported history also gives history learning data from day one. Files are read in the browser so customer details never reach the server |
| 2026-10-26 | Reorder into phases: foundations, scheduling engine, playground, full product, extras. The playground (sub-plan 08) comes before accounts, admin screens and emails | Proves the riskiest, most distinctive part first; the data model settles before admin screens are built on it; a shareable demo after 8 sub-plans instead of 14 |
| 2026-10-26 | The playground runs on the real foundations (tenancy, engine, booking rules, database constraint); each visitor's sandbox is an ordinary business; scheduling code receives the business explicitly | Nothing in the demo is throwaway, so building it early doesn't create rework |
| 2026-10-26 | Use the latest stable versions rather than the ones sub-plan 01 named: PHP 8.5, Node 26, PostgreSQL 18 (the newest Neon offers), nginx 1.30 from nginx.org, PHPUnit 13, `@vueuse/core` 15 | Start current rather than upgrade soon after. Release candidates (PHP 8.6, PostgreSQL 19) are left out |
| 2026-10-26 | Stay on TypeScript 6 and `@laravel/passkeys` 0.2 | TypeScript 7 no longer ships the JavaScript API that `vue-tsc` needs. The passkeys client stays matched to the PHP `laravel/passkeys` package (0.2.1, its latest) |
| 2026-10-26 | nginx from nginx.org, not Debian | Debian's nginx 1.26 passes the Host header to PHP without its port, so redirects lost `:8001`. nginx 1.30 can rebuild it safely with `$request_port` |
| 2026-10-26 | PHP-FPM listens on `127.0.0.1:9000` only | The base image listens on every interface, which would expose FastCGI to anything on the same network (including Fly's private network) |
| 2026-10-26 | `phpunit.xml` sets `APP_ENV`, `DB_CONNECTION`, `DB_DATABASE` and `DB_URL` as `<server>` variables as well as forced `<env>` ones | Laravel reads `$_SERVER` first, and `force="true"` doesn't touch it, so the app container's `APP_ENV=local` was leaking into tests (CSRF then failed). `DatabaseConnectionTest` confirms tests stay on `testing` even when `DB_DATABASE` or `DB_URL` point elsewhere |
| 2026-10-26 | Vite+'s built-in `vp test` (Vitest 5) with jsdom; no separate Vitest install | Worked without changes |
| 2026-10-26 | `docs/` and Boost's generated files are left out of Oxfmt | Keeps the hand-written docs as they are (Oxfmt would reflow every table), and stops Boost updates from failing `npm run check` |
| 2026-10-26 | Boost is set up for Claude Code and Cursor. The project's own guidelines live in `.ai/guidelines/project.md`, and Boost writes them into both `CLAUDE.md` and `AGENTS.md` | One source for both agents. Edit `.ai/guidelines/project.md`, then run `php artisan boost:update`; don't edit the generated files |
| 2026-10-26 | MCP servers start through `cmd /c` (Boost's as well as shadcn-vue's) | On native Windows, Claude Code and Cursor start commands without a shell, so they skip Herd's `php.bat` and found an old PHP 8.4.0 instead. `boost:install` writes plain `php` back, so re-apply this after reinstalling |
| 2026-10-27 | Develop inside WSL 2 (Ubuntu), with the code on the Linux filesystem (sub-plan 01b) | Faster file access and tests; file names case-sensitive as in production; removes the Windows-only workarounds (Vite polling, `cmd /c` MCP commands, Herd PATH clashes). Docker recommends it with Docker Desktop's WSL 2 backend |

## Notes for the next session

- `D:\projects\booking-app` already contains `docs/` (with `planning/` inside), so `laravel new` and `composer create-project` can't install straight into it. Create the app in a temporary folder and move the files across. **Don't use the Laravel installer's `--force` flag here: it deletes the existing folder, `docs/` included.**
- This repo will be public, so keep the planning docs about the product. Leave out personal or job-search notes.
- The Windows host's tools are out of date (Herd's PHP 8.4.16, Node 22.17.1). Sub-plan 01b replaces them with PHP 8.5 and Node 26 inside Ubuntu, so don't upgrade them for this project.
- For sub-plan 02:
  - The starter kit already ships `.github/workflows/tests.yml` and `.github/dependabot.yml`. Rework them rather than starting again:
    - The workflow would fail as it stands: it has no PostgreSQL service and uses PHP 8.3 and Node 22. Give it a PostgreSQL 18 service, PHP 8.5 and Node 26.
    - It runs `composer ci:check`, which covers Pint, Larastan, PHPUnit, `npm run check` and `npm run types:check`. Add `npm run test` (Vitest), which `ci:check` leaves out.
    - It already uses least-privilege `permissions` and actions pinned to commit SHAs. Keep both.
    - `dependabot.yml` only covers GitHub Actions. Add Composer, npm and the Docker base images.
  - Protect `main`: merge only through pull requests, and require CI to pass first.
  - Add `composer audit` and `npm audit` to CI. Turn on GitHub's secret scanning and push protection, which are free for public repos.
  - Raise Larastan from level 7 to 8, which adds null checks. Level 8 currently reports 10 errors. All of them come from `$request->user()` possibly being null in the settings controllers and request, and Laravel's `#[CurrentUser]` attribute fixes them properly. Levels 9 and 10 mostly flag framework code, so stop at 8.
  - Decide on production error monitoring, for example Laravel Nightwatch or Sentry.
  - Settle the commit author email before the first push (see 01b, step 3).
  - Laravel builds URLs, including password reset links, from the request's Host header. Configure trusted proxies for Fly and consider `trustHosts()`, checking that Fly's health checks still pass.
  - Supervisor keeps the container running even if nginx or PHP-FPM gives up, so Fly's HTTP health check on `/up` is what catches a broken machine.
  - The production image is about 970MB, mostly the official `php:8.5-fpm` base image.
