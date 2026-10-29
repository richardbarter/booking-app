# Tracking

Last updated: 2026-10-29

## Current status

Sub-plan 01 is done. The Laravel 13 Vue starter kit runs in Docker (PostgreSQL 18, PHP 8.5, Node 26, Mailpit), tests run on PostgreSQL in the container and on the host, the production image builds, and Laravel Boost is set up for Claude Code and Cursor.

Sub-plan 01b moved development into WSL 2. The code is at `~/projects/booking-app` in Ubuntu, the dev containers run as the developer's user and share `vendor` and `node_modules` with Ubuntu, and the Windows-only workarounds are gone. PHPUnit's 42 tests take about 2.5s, in the container or in Ubuntu, against 7.3s on Windows.

Sub-plan 02 is built, in pull request https://github.com/richardbarter/booking-app/pull/1. Bookalive has been live at https://bookalive.fly.dev since 2026-10-28, and every production check in the sub-plan passed:
- **Hosting:** one Fly Machine (`shared-cpu-1x`, 512MB) in `lhr`, with a shared IPv4 and an IPv6 address. It suspends after about 3.5 minutes without visitors, and Fly's health checks stop while it's suspended. The Neon project `bookalive` is in AWS Europe (London), with Postgres 18 and autoscaling from 0.25 to 0.5 CU. Fly's release command runs the migrations before each release.
- **Waking up:** Neon's compute stayed idle overnight on 2026-10-28, with the Machine suspended. The first visit after that woke both in 1.35s (measured through a VPN that added about 0.05s to each round trip). Warm requests take about 0.1s, and `/up` about 0.035s. After a deploy the Machine is stopped rather than suspended, so the first visit is a full boot, which took 5.4s.
- **CI:** `ci.yml` replaces the starter kit's `tests` workflow. It runs the `checks` and `audit` jobs on every pull request, every push to `main` and every Monday, and the `deploy` job on `main`. Every action is pinned to a commit. The `main` ruleset only accepts pull requests that pass `checks` and `audit`.
- **Security:** Dependabot covers Composer, npm, the Dockerfile, Docker Compose and GitHub Actions. It ignores TypeScript 7, `@laravel/passkeys` from 0.3 (until the PHP package moves) and PostgreSQL majors in Docker Compose (which follow Neon). Secret scanning with push protection, private vulnerability reporting and CodeQL (`actions` and `javascript-typescript`) are on. Security headers come from `bepsvpt/secure-headers` 9.1. The client IP comes from `Fly-Client-IP`, and forged IPs still get rate limited. Sign-up is off.
- **Monitoring:** Nightwatch 1.30 (EU region) receives production's errors and requests, but not Fly's health checks. Its agent runs under Supervisor in production only.
- **Code:** Larastan at level 9 with no errors. PHPUnit has 54 tests.
- **The deploy token expires on 2027-10-28.** The `production` environment's `FLY_API_TOKEN` is a deploy token named `github-actions`, limited to this app. CI deploys fail after it expires, until it's replaced with the command in sub-plan 02, step 4.3.
- **Still to check after the merge** (step 8): the `deploy` job deploys `main` to Fly; Dependabot reports no config errors under **Insights → Dependency graph → Dependabot** (it only reads its config from `main`); and the repo's website is set to https://bookalive.fly.dev.

**Development environment** (installed for 01b, 2026-10-27):
- WSL 3.0.3, with Ubuntu 26.04 LTS as the default distro
- Docker 29.9.2 and Compose v5.6.1, through Docker Desktop's WSL integration
- PHP 8.5.4 from Ubuntu's packages; Composer 2.10.3 from the official installer
- Node 26.12.1 and npm 11.20.0 from NodeSource
- GitHub CLI 2.103.3
- Claude Code 2.1.311 (native installer); Cursor through Anysphere's WSL extension

## Next up

1. Finish sub-plan 02's step 8 once pull request #1 merges: check the three things under **Still to check after the merge**. Record the results here in a `docs/` pull request, which marks 02 as done. Merging it deploys again, which tests the automatic deploy a second time.
2. Write sub-plans 03 to 07 as one set: the data model, then the engine. The engine plans share interfaces and the randomised rule checks, so they're best designed together. Build them in order, then plan 08.

Use Laravel Boost's docs search while planning, so the plans use the installed versions' APIs.

## Sub-plan status

Status values: Not started, Planned (plan written), In progress, Done.

| # | Sub-plan | Status | PR | Notes |
|---|---|---|---|---|
| | **Phase 1: Foundations** | | | |
| 01 | Project setup | Done | — (merged locally; no remote until 02) | Uses newer versions than the plan; see the decisions log |
| 01b | WSL development environment | Done | — (merged locally; no remote until 02) | Added after 01: moves development off the Windows filesystem. PHPUnit 2.5s, down from 7.3s on Windows |
| 02 | CI and deployment | In progress | #1 | Built. Step 8's checks follow the merge. Live at https://bookalive.fly.dev |
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
| 2026-10-26 | ~~MCP servers start through `cmd /c` (Boost's as well as shadcn-vue's)~~ Superseded on 2026-10-27 by the move to WSL (01b) | On native Windows, Claude Code and Cursor start commands without a shell, so they skip Herd's `php.bat` and found an old PHP 8.4.0 instead. `boost:install` writes plain `php` back, so re-apply this after reinstalling |
| 2026-10-27 | Develop inside WSL 2 (Ubuntu), with the code on the Linux filesystem (sub-plan 01b) | Faster file access and tests; file names case-sensitive as in production; removes the Windows-only workarounds (Vite polling, `cmd /c` MCP commands, Herd PATH clashes). Docker recommends it with Docker Desktop's WSL 2 backend |
| 2026-10-27 | Test the scheduling engine with randomised rule checks (plain PHPUnit, fixed seed) and mutation testing (Infection, engine only). Check pages for accessibility with axe in Playwright. `infection/infection` and `@axe-core/playwright` are approved as dev dependencies | The engine is the hardest part to get right. Random diaries find edge cases that hand-written examples miss, and mutation testing shows whether the tests would actually catch a bug. axe is cheap to add once Playwright arrives in 08 |
| 2026-10-27 | PHP in Ubuntu comes from Ubuntu's own packages, not a third-party package source | It's only used for tools (Composer, Boost's MCP server, Pint, Larastan, the editor). The app and its tests run on the container's PHP, and apt keeps Ubuntu's PHP patched |
| 2026-10-27 | The GitHub CLI logs in with expiring tokens (`gh auth login --expiring`): an 8-hour token, renewed automatically with a 6-month refresh token | WSL has no system keyring, so `gh` stores its token in a plain-text file. A short-lived token limits the damage if that file ever leaks |
| 2026-10-27 | Drop the `vendor`, `node_modules` and `bootstrap/cache` container volumes. Ubuntu and the containers share one install through the bind mount, as in Laravel Sail | Both are glibc x64 Linux with PHP 8.5 and Node 26, so the same packages work in each. There are no longer two installs to keep in step, or named volumes going stale after a rebuild, and the editor and Boost see the same packages as the app. `bootstrap/cache` was only separate because the two installs differed. The dev image shrinks from 1.96GB to 1.19GB. A fresh clone runs `composer install` and `npm ci` before `docker compose up` |
| 2026-10-27 | The dev containers run as the developer's user: in the `dev` image, `www-data` takes the UID and GID from the `WWWUSER` and `WWWGROUP` build arguments (default 1000), as in Sail. The production `php` stage keeps UID 33 | Linux enforces file ownership on the bind mount. As UID 33 the containers couldn't write to `storage/`, and the files they created (logs, compiled views, Wayfinder output, `make:` files) couldn't be edited in Ubuntu. Named like Sail's, and not `UID`/`GID`, because bash's `UID` is read-only |
| 2026-10-27 | Vite watches files natively; `WATCH_USE_POLLING` and the polling option are gone | With the code on the Linux filesystem, file-change events reach the containers |
| 2026-10-27 | MCP servers start with plain `php artisan boost:mcp` and `npx shadcn-vue@latest mcp` again, in `.mcp.json` and `.cursor/mcp.json` | Claude Code and the editor run inside Ubuntu, where `php` is Ubuntu's PHP 8.5. Run inside WSL, `boost:install` writes a `wsl.exe` command with absolute paths instead (meant for editors running on Windows), so put the plain commands back after reinstalling. `boost:update` leaves them alone |
| 2026-10-27 | Backend code is organised by type, Laravel's default (controllers, models, requests, policies and so on), with subfolders by area such as `Http/Controllers/Admin/`. Single-purpose actions go in `app/Actions`, other services in `app/Services`, and the scheduling engine in its own folder, `app/Scheduling` | The most common Laravel layout, and the one `make:` commands, the starter kit and Boost's guidelines expect. Feature folders don't suit this app, because the playground, admin area, public booking page and statistics all share the same models. The engine is plain PHP that's tested on its own and is the only code Infection mutation-tests, so its own folder keeps it separate and easy to target |
| 2026-10-27 | Frontend code is organised by type, as in the starter kit (`pages`, `layouts`, `components`, `composables`), with subfolders by area. Components and composables used by one area go in that area's folder (e.g. `components/admin/`). Shared ones go in a folder named after their topic (e.g. `components/scheduling/`), not loose at the top of `components/`. An area's component moves to a topic folder once a second area needs it | Keeps what's good about feature folders, since a component's folder shows who uses it, without their cost. Inertia finds pages by name in `resources/js/pages`, so feature folders would need a custom page resolver and extra paths in `config/inertia.php`, and Boost's Inertia guidance assumes `pages`. The areas also overlap a lot (the playground timeline becomes the admin calendar, and the strictness slider appears in both), so many components are shared anyway |
| 2026-10-27 | Write sub-plans a few at a time, shortly before building them, rather than all at once. The overview's sub-plan table already sketches every one | Later plans depend on what earlier ones settle (the tables from 03, the engine's interfaces from 04, the queue setup from 02), so detailed plans written too early would need rewriting |
| 2026-10-27 | The product is called Bookalive, with only the B capitalised. The Fly app is `bookalive` (`bookalive.fly.dev`), and the repo stays `booking-app` | Short, and it reads as one word. "Alive" suits availability that's always worked out from the current diary. Two UK booking systems have similar names (BookingLive, BookItLive); that's accepted. The name is cheap to change until links are shared, but a Fly app can't be renamed, so changing it later means a new app and a new URL |
| 2026-10-27 | Commits use GitHub's no-reply address, set in the global git config. The existing commits were rewritten to use it before the first push | Commit emails become public with the repo and can't be removed once pushed. Commits still link to the GitHub account. The rewrite changed only the email: the files, dates, messages and merges are the same |
| 2026-10-28 | Set Neon up in its console only. Don't follow the agent prompt Neon shows after creating a project (install the `neon` CLI, `neon init`, `neon deploy`) | That prompt is for JavaScript apps that use Neon in local development. `neon deploy` would write the production connection string into the local `.env`, and `neon init` adds Neon's own config file (`neon.ts`) to the repo. Here Neon is production only: local development and tests use the Docker PostgreSQL, and Laravel's `pgsql` connection reads `DB_URL`, which goes straight from the console to Fly as a secret |
| 2026-10-28 | Trust only the address that connects (`trustProxies(at: 'REMOTE_ADDR')`), and take the client IP from Fly's `Fly-Client-IP` header through a `UseFlyClientIp` middleware. Production checks that forged IPs still get rate limited | Laravel 13's `at: '*'` trusts every address in `X-Forwarded-For`, so `ip()` returned an address the client wrote itself, and the IP-based rate limiters could be dodged. Fly's `X-Forwarded-For` ends with the app's own IP, so no position in it is reliably the client, and Fly recommends `Fly-Client-IP`. Its docs don't say whether a client can forge that header, hence the production check |
| 2026-10-28 | Sign-up is switched off with its own setting, `REGISTRATION_ENABLED` (on by default, `"false"` in `fly.toml`), not by `APP_ENV`. A shared `canRegister` prop hides the links | The image's assets stage has no `APP_ENV`, so Laravel treats it as production. Wayfinder wouldn't generate the register routes, and the pages that import them would break the build. 09 removes the setting when owner sign-up arrives |
| 2026-10-28 | Security headers come from `bepsvpt/secure-headers`, replacing the hand-written `AddSecurityHeaders` middleware. The Content Security Policy in 11 uses `spatie/laravel-csp`. Both are approved as dependencies | A widely used pair, with every header set and documented in one config file. `config/secure-headers.php` holds only what differs from the package's defaults (HSTS on, CSP off, `strict-origin-when-cross-origin` because `no-referrer` breaks `redirect()->back()` after Inertia visits, and `Cross-Origin-Opener-Policy: same-origin`). The package merges its defaults for the rest, so they keep up with package updates |
| 2026-10-28 | No licence for now, so all rights are reserved. `composer.json` says `"proprietary"`, and the README says the code is public to read but not licensed for reuse | Keeps the option of turning Bookalive into a real product. An open licence such as MIT can be added later, but code published under one can't be taken back |
| 2026-10-28 | Nothing in production queries the database on a timer. Fly's health checks call `/up`, which never touches the database; sessions and cache use the database only when someone visits; the queue moves to Redis (Upstash) in 12; the scheduler (08) runs tasks hourly or less often | Neon's Free plan gives 100 CU-hours a month, and a 0.25 CU compute that never sleeps uses about 180, so it has to sleep more than half the time. Neon sleeps after 5 minutes without queries. Confirmed overnight on 2026-10-28 |
| 2026-10-28 | The app connects to Neon directly, not through its pooler, over TLS with the certificate checked (`sslmode=verify-full&sslrootcert=system`) | PHP-FPM opens at most 5 connections on one Machine, well under Neon's limit. The pooler runs PgBouncer in transaction mode, which has caveats for session settings and prepared statements, and migrations should use a direct connection anyway. Revisit when the queue worker and Reverb add connections |
| 2026-10-28 | One Fly Machine, which suspends when idle (`auto_stop_machines = "suspend"`, `min_machines_running = 0`) and resumes on the next request | A demo has long quiet spells. Resuming is quick (1.35s for the first visit, including waking Neon, against 5.4s for a full boot), and a suspended Machine costs only its storage |
| 2026-10-28 | Production monitoring is Laravel Nightwatch (Free plan, EU region), recording 100% of requests but not Fly's health checks | Laravel's own service: it records requests, queries, jobs, scheduled tasks and emails as well as errors, which matters once the scheduler (08) and queue (12) arrive. The Free plan's 300,000 events a month is far more than a demo uses. Browser errors aren't covered; Sentry's Vue package can be added with the widget (18) |
| 2026-10-28 | CI deploys with a Fly deploy token limited to the `bookalive` app, stored in the GitHub `production` environment (deployments from `main` only). It expires on 2027-10-28 | A deploy token can't touch other apps or the organisation, and an expiry limits the damage if it ever leaks. Replace it before then with the command in sub-plan 02, step 4.3 |
| 2026-10-29 | Each main step's heading in a sub-plan starts with "Step" and its number (`### Step 3: Neon`). The numbered items inside a step don't, and are referred to as step 3.2. The overview's sub-plan template says so | Searching the docs for "step 3:" finds the heading straight away. Searching for "3." doesn't work, because it matches every list item and version number |

## Notes for the next session

- This repo will be public, so keep the planning docs about the product. Leave out personal or job-search notes.
