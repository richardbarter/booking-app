# 02 — CI and deployment

## Goal

Put the code on GitHub with CI on every pull request, and deploy every merge to `main` to Fly.io automatically, with the database on Neon. Bookalive is live at `https://bookalive.fly.dev` from here on, and every later sub-plan is merged only once CI passes.

## Depends on

01 (project setup) and 01b (WSL development environment).

## Scope

**In scope**
- A public GitHub repo (`booking-app`), with `main` protected by a ruleset and GitHub's free security features turned on
- A CI workflow: lint, types, tests on PostgreSQL 18, build, Vitest, and dependency audits
- Dependabot for Composer, npm, Docker images and GitHub Actions
- A Fly.io app (`bookalive`, London) running the production image, with migrations run on each deploy
- A Neon project (AWS London, Postgres 18)
- Automatic deploys from `main` after CI passes
- Production settings: trusted proxies and hosts, baseline security headers, logs, sign-up turned off until 09
- Error monitoring with Laravel Nightwatch
- Larastan raised from level 7 to 9
- The name Bookalive in the app, README and project guidelines
- Deciding how background processes run without keeping Neon awake (built in 08, 12 and 17)

**Out of scope**
- Custom domain (see the open question in the overview)
- Scheduler, queue worker and Reverb processes (08, 12 and 17)
- Production email (12)
- A Content Security Policy (11, see Notes)
- Browser error monitoring (see Notes)
- Cloudflare R2 (first needed for uploads)

## Data model

No changes.

## Production setup

| Part | Choice |
|---|---|
| Web | One Fly Machine in `lhr`: `shared-cpu-1x`, 512MB. nginx and PHP-FPM run under Supervisor as now, joined by the Nightwatch agent. The Machine suspends when idle and resumes on the next request |
| Database | Neon Free plan in AWS London (`aws-eu-west-2`), Postgres 18, direct (unpooled) connection over TLS with the server certificate checked. Autoscaling from 0.25 to 0.5 CU |
| Migrations | Fly's `release_command` runs `php artisan migrate --force` on a temporary Machine before each release. A failed migration stops the deploy |
| Sessions and cache | The `database` drivers, as now. Only visitors' requests touch them |
| Queue | `sync` until 12. Nothing is queued yet, and there's no worker to run it |
| Mail | `log` until 12 |
| Logs | `stderr`, read with `fly logs` |
| Monitoring | Laravel Nightwatch, Free plan, EU region |
| Deploys | GitHub Actions, after CI passes on `main`. Fly builds the image on its remote builder, from the `php` stage of the Dockerfile |

### Keeping Neon asleep

Neon's Free plan gives each project 100 CU-hours of compute a month. The smallest compute is 0.25 CU, so a database that never sleeps uses about 180 CU-hours a month. It has to be asleep more than half the time. Neon puts the compute to sleep after 5 minutes without queries, and this can't be turned off on the Free plan. If the month's hours run out, the database stops until the next month.

**Rule: nothing in production queries the database on a timer.** Only real visitors should wake it.
- Fly's health check calls `/up`. Laravel registers it outside the `web` middleware group, so it doesn't start a session and never touches the database.
- Sessions and cache use the database, but only when someone visits.

How each later background process follows the rule:
- **Scheduler (08):** its own Fly process group running `php artisan schedule:work` on the smallest Machine, always on (about $2 a month). Tasks run hourly or less often where possible, so the database wakes briefly once an hour at most.
- **Queue (12):** Redis (Upstash), not the database queue. A database queue worker checks for new jobs every few seconds, which would keep Neon awake all the time. The worker runs alongside the scheduler. 12 chooses between Upstash through Fly or directly, and sets how often the worker polls, so it stays inside Upstash's free 500,000 commands a month.
- **Reverb (17):** decided then. Open WebSocket connections keep a Machine awake, but they don't touch the database.

### Costs

- **Fly** has no free plan for new organizations, only a short trial (2 hours of Machine time or 7 days), after which it needs a card. Organizations on the plans Fly discontinued in October 2024 keep those plans' free allowances, so an older account may cover some of this. The web Machine costs about $3.69 a month if it never suspends. While it's suspended, Fly charges only for its disk (about $0.15 per GB a month, and the image is about 1GB). A demo with light traffic should cost around $1 a month, plus about $2 for the scheduler Machine from 08. Use Fly's shared IPv4 address; a dedicated one costs $2 a month.
- **Neon, Nightwatch and GitHub:** free plans.

## Steps

Steps marked **(you)** need your accounts or a browser. Claude Code does the rest, and can run the `gh`, `fly` and `git` commands once you're logged in.

### 1. Accounts and tools (you)

1. **Fly.io:** sign up at fly.io and add a card. In Ubuntu, install `flyctl` with the command from Fly's install docs (`curl -L https://fly.io/install.sh | sh`), add it to your `PATH` as the installer says, then run `fly auth login`.
2. **Neon:** sign up at neon.com. Signing in with GitHub is fine.
3. **Nightwatch:** sign up at nightwatch.laravel.com and create an application named Bookalive in the **EU** region, with a `production` environment. Its token stays in Nightwatch (the application's settings, under **Environments**) until step 4, and only ever goes to Fly, never into a local `.env`. Skip the setup steps Nightwatch shows after that: this plan installs the package (step 5.4), sets the token (step 4) and runs the agent under Supervisor (step 5.4). Request sampling stays at the default 100% (see Notes).
4. **Licence:** none for now, so all rights are reserved (see Notes). Before the first push, Claude sets `license` in `composer.json` to `"proprietary"` and adds a line to the README saying the code is public to read but all rights are reserved.

### 2. GitHub repo

1. **Review what goes public.** Check `git ls-files` for anything that shouldn't be public. The Claude Code and Cursor skills under `.claude/` and `.cursor/` are fine to publish, and `.claude/settings.local.json` is ignored. The only `.env` file ever committed is `.env.example`.
2. **Create and push.** Merge this plan and the licence change into `main` first, then:
   ```bash
   gh repo create booking-app --public --source=. --remote=origin \
       --description "Online booking for appointment-based businesses that keeps the diary free of unbookable gaps"
   git push -u origin main
   ```
   This push goes straight to `main`, before the ruleset exists. Everything after it goes through a pull request.
3. **Repo settings:**
   - Allow merge commits only (the history already uses them), and delete branches automatically after merging.
   - Under **Actions → General**, keep the default token read-only, and don't let Actions create or approve pull requests.
4. **Ruleset for `main`:** require a pull request (with no approvals, as there's one developer), require the `checks` and `audit` jobs to pass, and block force pushes and deletion. Leave the bypass list empty.
5. **Security** (all free for public repos): turn on Dependabot alerts and security updates, secret scanning with push protection, private vulnerability reporting, and CodeQL's default setup (it covers the TypeScript and the workflows, not PHP).
6. **`production` environment:** allow deployments from `main` only. Its `FLY_API_TOKEN` secret is added in step 4.

### 3. Neon (you)

1. Create a project named `bookalive` in **AWS Europe (London)** with **Postgres 18**, and name its database `bookalive`.
2. Set autoscaling to 0.25–0.5 CU.
3. Copy the connection string with **connection pooling turned off** (the host has no `-pooler`). Replace its query string with `?sslmode=verify-full&sslrootcert=system`, which checks Neon's certificate against the image's system CA list. `sslrootcert=system` needs libpq 16 or later, and Debian trixie's is 17. Laravel doesn't pass on the `channel_binding` option that Neon adds.

### 4. Fly app and secrets (you, with Claude)

1. Run `fly platform regions` to confirm `lhr` is still offered, then create the app without launching it, in your existing personal organization: `fly apps create bookalive --org personal`. That organization keeps the old plan's free allowances, and a new organization wouldn't get them. Don't use `fly launch`, which writes its own Dockerfile and settings for Laravel.
2. Set the secrets with `fly secrets import --stage`, which reads them from standard input, so they don't end up in your shell history. `read -rs` asks for each value without showing it, and the `DB_URL` line swaps the query string Neon gives for the one from step 3:
   ```bash
   echo "APP_KEY=$(php artisan key:generate --show)" | fly secrets import --stage -a bookalive
   read -rsp 'Neon connection string: ' url && echo \
       && printf 'DB_URL=%s?sslmode=verify-full&sslrootcert=system\n' "${url%%\?*}" \
       | fly secrets import --stage -a bookalive; unset url
   read -rsp 'Nightwatch token: ' token && echo \
       && printf 'NIGHTWATCH_TOKEN=%s\n' "$token" \
       | fly secrets import --stage -a bookalive; unset token
   fly secrets list -a bookalive   # names and digests only, never the values
   ```
3. Create a deploy token that only works for this app and expires in a year, and send it straight to the GitHub environment:
   ```bash
   fly tokens create deploy -a bookalive --expiry 8760h \
       | gh secret set FLY_API_TOKEN --env production --repo richardbarter/booking-app
   ```

### 5. App changes (on `feature/02-ci-deployment`)

1. **Name.** Set `APP_NAME="Bookalive"` in `.env.example`. Change the heading in `.ai/guidelines/project.md`, then run `php artisan boost:update`, which rewrites `CLAUDE.md` and `AGENTS.md`. Update the README title. Replace the starter kit's `name` and `description` in `composer.json`. Optionally change `APP_NAME` in your own `.env` too; it logs you out locally once.
2. **`fly.toml`**, written by hand. This sketch shows the intended settings; check the keys against Fly's configuration reference:
   ```toml
   app = "bookalive"
   primary_region = "lhr"

   [build]
     build-target = "php"          # the last stage is `dev`, which must not be deployed
     [build.args]
       VITE_APP_NAME = "Bookalive"

   [deploy]
     release_command = "php artisan migrate --force"

   [env]
     APP_NAME = "Bookalive"
     APP_ENV = "production"
     APP_DEBUG = "false"
     APP_URL = "https://bookalive.fly.dev"
     DB_CONNECTION = "pgsql"
     LOG_CHANNEL = "stderr"
     LOG_LEVEL = "info"
     MAIL_MAILER = "log"
     QUEUE_CONNECTION = "sync"
     SESSION_SECURE_COOKIE = "true"

   [http_service]
     internal_port = 8080
     force_https = true
     auto_stop_machines = "suspend"
     auto_start_machines = true
     min_machines_running = 0

     [[http_service.checks]]
       method = "GET"
       path = "/up"
       interval = "30s"
       timeout = "5s"
       grace_period = "10s"

   [[vm]]
     size = "shared-cpu-1x"
     memory = "512mb"
   ```
   The entrypoint already caches config at startup and runs `release_command` through `exec "$@"`, so it needs no changes. `NIGHTWATCH_REQUEST_SAMPLE_RATE` is left out on purpose, so Nightwatch records every request (see Notes). The real `fly.toml` also sets `REGISTRATION_ENABLED = "false"` (item 7) and gives the health check a `Host` header (item 5).
3. **Dockerfile.** Add `ARG VITE_APP_NAME` to the `assets` stage before `npm run build`. Vite reads `VITE_*` variables from the environment, and the image has no `.env`, so without it page titles would say "Laravel".
4. **Nightwatch** (a new dependency, approved with this plan). Run `composer require laravel/nightwatch`. Add `NIGHTWATCH_ENABLED=false` to `.env.example`, because there's no agent locally; `phpunit.xml` already turns it off for tests. Run the agent under Supervisor in production only: add an `[include]` for `/etc/supervisor/conf.d/*.conf` to `supervisord.conf`, copy a `nightwatch.conf` (running `php artisan nightwatch:agent`) into that folder in the `php` stage, and delete it again in the `dev` stage.
5. **Proxies and hosts** in `bootstrap/app.php`:
   - `trustProxies(at: 'REMOTE_ADDR', headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO)`, which trusts whichever address connected. That's safe on Fly, because a Machine can only be reached from the internet through Fly's proxy. Not `at: '*'`: in Laravel 13 that trusts every address in `X-Forwarded-For`, so `ip()` returns the leftmost entry, which the client writes itself. Only these two headers are trusted: Fly passes the browser's `Host` header through unchanged, so `X-Forwarded-Host` and `X-Forwarded-Port` are never needed, and trusting them would let anyone rewrite the host in password reset links.
   - A `UseFlyClientIp` middleware, prepended to the global stack, puts Fly's `Fly-Client-IP` header in place of `X-Forwarded-For`. Fly's `X-Forwarded-For` holds whatever the client sent, with the app's own IP as the rightmost entry, so no position in it is reliably the client. Fly recommends `Fly-Client-IP` instead. Its docs don't say whether Fly overwrites one a client sends, so a production check tests it.
   - `trustHosts()`, which accepts only `APP_URL`'s host (and its subdomains) outside local and tests. `TrustProxies` reads the host too, so a request with any other `Host` is rejected with a 400 before it reaches a route. Fly's health checks reach the Machine directly rather than through `bookalive.fly.dev`, so the check sends `Host: bookalive.fly.dev`.
6. **Security headers** with `bepsvpt/secure-headers` (approved during 02), its `SecureHeadersMiddleware` appended to every response. The first build used a hand-written `AddSecurityHeaders` middleware; the package replaced it. `config/secure-headers.php` holds only the settings that differ from the package's defaults, which it merges for every other key, so those keep up with package updates:
   - `Referrer-Policy: strict-origin-when-cross-origin`, not `no-referrer`. `url()->previous()`, which `redirect()->back()` uses after a validation error, reads the `Referer` header first, and its session fallback skips Inertia visits (they're XHR requests), so `no-referrer` would send people back to the wrong page
   - `Cross-Origin-Opener-Policy: same-origin`, not `unsafe-none`, so windows from other sites can't reach ours
   - `Strict-Transport-Security: max-age=31536000`, turned on, without `includeSubDomains` or `preload` until the custom domain is set up. The package sends it over HTTP too, where browsers ignore it
   - its CSP turned off: the Content Security Policy comes from `spatie/laravel-csp` in 11

   Everything else is the package's default: `nosniff`, `X-Frame-Options: sameorigin` (18 relaxes framing for `/embed/*`), and a `Permissions-Policy` that limits about 30 browser features to our own origin.
7. **Sign-up off in production until 09.** In `config/fortify.php`, include `Features::registration()` only while `REGISTRATION_ENABLED` is true (the default), and set it to `"false"` in `fly.toml`. It can't follow `APP_ENV`: the image's assets stage runs without one, so Laravel treats the build as production, and Wayfinder wouldn't generate the register routes that `Welcome.vue`, `Login.vue` and `Register.vue` import, which breaks the build. A shared `canRegister` Inertia prop hides the Register and Sign up links when registration is off. Until 09 there's nothing behind an account, and with mail going to the log, nobody could verify their email or reset their password. This keeps strangers' personal data out of the live database. 09 turns registration back on, as the owner sign-up.
8. **Larastan level 9.** Raise `level` in `phpstan.neon` to 9 and fix its 13 errors properly, with no ignore rules or baseline:
   - 9 come from `$request->user()` possibly being null in the settings controllers and request. Laravel's `#[CurrentUser]` attribute fixes them.
   - 1 is a passkey's `created_at`, which the passkeys package types as nullable (its table uses `timestamps()`). The security page now handles a missing date, in PHP, the `Passkey` type and `PasskeyItem.vue`.
   - 2 are a real bug in the login and passkey rate limiters in `FortifyServiceProvider`. They use form input as a string without checking, so a login request with `email[]=x` returns a 500 error instead of a validation error. Check the value with `is_string()` before using it, and fall back to the IP address alone (or the session ID, for passkeys). `$request->string()` isn't enough, because it converts an array to a string, which PHP still reports as an error.
   - 1 is `parse_url(config('app.url'))` in `config/fortify.php`. Use `config()->string('app.url')`, which throws if the value isn't a string.

   Level 10 isn't used: its only extra errors come from Inertia's `Route::inertia()` helper, which doesn't declare a return type.
9. **`composer ci:check`:** add `npm run test` (Vitest), so CI and a local `composer ci:check` run the same checks.

### 6. CI and Dependabot

**Replace `.github/workflows/tests.yml` with `ci.yml`.** Keep what it already does well: least-privilege `permissions: contents: read`, every action pinned to a commit SHA with its version in a comment, and `persist-credentials: false` on checkout.

- **Triggers:** pull requests, pushes to `main`, and a weekly schedule, so the audits catch new advisories even when the code hasn't changed.
- **Concurrency:** one run per branch. A new push cancels the older run on pull requests, but never on `main`.
- **`checks` job:**
  - a `postgres:18-trixie` service with `POSTGRES_DB: testing`, published on port 5434 so `.env.example`'s database settings work unchanged
  - PHP 8.5 (`shivammathur/setup-php`) with the Dockerfile's extensions, and Node 26 with npm caching
  - `composer install`, copy `.env.example` to `.env`, `php artisan key:generate`, `npm ci`, `npm run build`, then `composer ci:check`

  `composer setup` is no longer used, because it migrates the development database.
- **`audit` job:** `composer audit --locked` and `npm audit --audit-level=high`. Neither needs the packages installed.
- **`deploy` job:**
  - runs only on pushes to `main`, after `checks` and `audit` pass
  - uses the `production` environment, in a concurrency group that never cancels a deploy in progress
  - sets up `flyctl` with `superfly/flyctl-actions/setup-flyctl`, pinned to a SHA, then runs `flyctl deploy --remote-only` with `FLY_API_TOKEN`. Use tag `1.6`: the latest GitHub release, `1.5`, still runs on Node 20, which GitHub Actions has retired, and `1.6` moved it to Node 24
- Give every job a `timeout-minutes`.

**Dependabot.** Add the `composer`, `npm`, `docker` (the Dockerfile) and `docker-compose` ecosystems next to `github-actions`. Check them weekly, group minor and patch updates per ecosystem, and keep the existing 5-day cooldown, so a newly published release has time to be pulled if it turns out to be bad. It ignores the updates the decisions log holds back: TypeScript 7, `@laravel/passkeys` beyond the PHP package's version, and PostgreSQL majors in Docker Compose, which follow Neon. GitHub reports config errors under **Insights → Dependency graph → Dependabot**.

### 7. First deploy (from the branch)

Deploy once by hand before opening the pull request, so a broken deploy never lands on `main`:

```bash
fly deploy --ha=false
```

`--ha=false` creates a single Machine; Fly would otherwise create two. Run `fly ips list` to check the app has a shared IPv4 address (not a dedicated one) and an IPv6 address. Then go through the production checks under **Tests**.

### 8. Pull request and automatic deploy

Open the pull request from `feature/02-ci-deployment`. Once `checks` and `audit` pass, merge it, and check that the `deploy` job deploys it. Then set the repo's website to `https://bookalive.fly.dev`.

### 9. Docs

- **README:** live URL, CI badge, and a short "Deployment" section (merges to `main` deploy automatically; secrets live in Fly and the GitHub environment).
- **Overview:**
  - section 7: add Nightwatch, and the queue driver (Redis, from 12)
  - section 8: describe the always-on approach
  - section 14: resolve the queue question
- **`tracking.md`:**
  - status and the live URL
  - decisions log: the Neon rule, direct connection, suspend, Nightwatch, security headers now and CSP in 11, sign-up off until 09, the deploy token's expiry
  - remove the 02 notes, and set **Next up** to sub-plans 03 to 07

## Tests

**Automated**
- A feature test for the trusted proxy settings: behind a trusted proxy, `request()->ip()` is `Fly-Client-IP` rather than anything in `X-Forwarded-For`; without it, entries before the proxy are ignored; `X-Forwarded-Proto: https` makes generated URLs `https`; and `X-Forwarded-Host` and `X-Forwarded-Port` are ignored.
- A feature test that pages share `canRegister`, false when registration is off.
- A feature test that pages carry the security headers with the referrer and opener policies we set (and no CSP yet), and send HSTS over HTTPS.
- Feature tests that a login request with an array email (`email[]=x`), and a passkey login with an array `credential.id`, get a validation error, not a 500.
- The whole suite and every check pass in CI on the pull request: Pint, Larastan at level 9, PHPUnit on PostgreSQL 18, Oxlint and Oxfmt, `vue-tsc`, the build, Vitest and both audits.

**Production checks** (after steps 7 and 8)
- `https://bookalive.fly.dev` shows the welcome page with "Bookalive" in the title, and `http://` redirects to `https://`.
- `/up` returns 200, and `fly logs` shows the release command running the migrations.
- `curl -sI https://bookalive.fly.dev/login` shows the security headers.
- There's no Register link, and `/register` returns 404.
- Links and assets use `https://bookalive.fly.dev`, with no `:8080`.
- `curl -s -H 'X-Forwarded-Host: evil.example' https://bookalive.fly.dev/login | grep -c evil.example` prints 0.
- Forged client IPs don't get round rate limiting. The passkey limiter allows 10 requests a minute per session and IP, so with one session cookie and a different made-up IP each time, the 11th request returns 429. If all 11 return 200, Fly passes a client's `Fly-Client-IP` through, and the IP setup needs another approach before relying on it:
  ```bash
  jar=$(mktemp); for i in $(seq 1 11); do curl -s -o /dev/null -w '%{http_code} ' -b "$jar" -c "$jar" \
      -H "Fly-Client-IP: 198.51.100.$i" -H "X-Forwarded-For: 198.51.100.$i" \
      https://bookalive.fly.dev/passkeys/login/options; done; echo; rm "$jar"
  ```
- A test exception (`fly ssh console -C "php artisan tinker --execute 'report(new Exception(\"Nightwatch test\"));'"`) shows up in Nightwatch.
- After 5 minutes with no visitors, Neon's console shows the compute as idle even though Fly's health checks keep running. `fly status` shows the Machine suspended, and the next visit wakes both.

## Done when

- [ ] The repo is public on GitHub, marked "all rights reserved", and `main` accepts changes only through pull requests that pass `checks` and `audit`
- [ ] Dependabot, secret scanning with push protection, private vulnerability reporting and CodeQL are on, and Dependabot reports no config errors
- [ ] CI passes on the 02 pull request, and merging it deploys to Fly automatically
- [ ] Bookalive runs at `https://bookalive.fly.dev` on one Machine in `lhr`, using Neon in London, and every production check under **Tests** passes
- [ ] Errors reach Nightwatch
- [ ] Larastan runs at level 9 with no errors
- [ ] The README, overview and `tracking.md` are updated

## Notes

- **No licence, for now.** `composer.json` said MIT, inherited from the starter kit, but there was no `LICENSE` file. With no licence the code is "all rights reserved": people can read it on GitHub, but not reuse it without permission. That keeps the option of turning Bookalive into a real product. An open licence such as MIT can be added later, but code published under MIT can't be taken back.
- **Why a direct connection, not Neon's pooler.** PHP-FPM opens at most a handful of connections (5 workers on one Machine), well under Neon's limit. The pooler runs PgBouncer in transaction mode, which has caveats for session settings and prepared statements, and migrations should use a direct connection anyway. Revisit if the queue worker and Reverb add many more connections.
- **Waking up.** The first visit after a quiet spell waits for Fly to resume the Machine (a few hundred milliseconds) and for Neon to start its compute. That's fine for a demo.
- **Deploys restart the only Machine,** so the site is down for a few seconds during each deploy.
- **A CDN in front of Fly would change the client IP.** `Fly-Client-IP` is the address Fly's proxy accepted the connection from, so behind Cloudflare or another proxy it becomes that proxy's address, and every visitor would share a few IPs in the rate limiters. If the custom domain puts a CDN in front, read the client IP from that CDN's header instead, trusting only its address ranges.
- **Content Security Policy in 11, with `spatie/laravel-csp`.** A CSP needs a nonce on Vite's scripts and care with reka-ui's inline styles, and the embed (18) needs `frame-ancestors` per route. 11 is the first page where customers enter their details, so the CSP arrives with it, and 18 adjusts it for `/embed/*`. `bepsvpt/secure-headers` keeps its own CSP turned off, so the two don't clash.
- **The deploy token expires after a year.** CI deploys start failing when it does. Record the expiry date in `tracking.md`, and create a new token with the command in step 4.
- **Watch Neon's usage in the first few weeks.** The console shows CU-hours used this month. If they're climbing faster than expected, something is waking the database.
- **Watch Nightwatch's usage too.** Nightwatch suggests recording 10% of requests, but Bookalive starts at 100%, the default. A demo's traffic is far below the Free plan's 300,000 events a month, and full traces make early problems easier to find. Errors are recorded either way: when Nightwatch reports an exception from a request it skipped, it uses the exception rate, which stays at 100%. If bots push usage up, add `NIGHTWATCH_REQUEST_SAMPLE_RATE = "0.1"` to `[env]` in `fly.toml`. It's a setting, not a secret.
- **Nightwatch covers the server side only.** It records errors, requests, queries, queued jobs, scheduled tasks and emails, which matters once the scheduler (08) and queue (12) arrive. Errors in the browser aren't covered. Sentry's Vue package can be added later for those, most likely with the widget (18), which runs on other people's sites.
- **Nightwatch pauses free projects after 30 days without events.** That only matters if the site goes quiet for a month.
- **The app uses Neon's owner role,** which can also run migrations. A separate role with fewer rights isn't worth the extra setup yet.
- **05 needs the `btree_gist` extension** for the exclusion constraint. Neon supports it, but check when building 05.
