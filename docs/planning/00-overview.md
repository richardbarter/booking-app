# Bookalive — Overview

> Progress, decisions and next steps are in [tracking.md](tracking.md). Each piece of work is planned in its own file in `sub-plans/`. For background on the scheduling problem, how other industries handle it and the research behind it, see [../background.md](../background.md).

## 1. Summary

An online booking system for small appointment-based businesses: physio and therapy clinics, sports massage therapists, salons, dog groomers and independent practitioners. Each business gets a public booking page and a "Book now" widget it can embed in its own website. Customers pick a service, see the available times and book without creating an account. The owner manages their services, working hours, time off and bookings from an admin area.

**Version 1 is for one person who works for themselves**: one login and one diary per business, with full control over everything. Businesses with staff come after v1, and the data model is designed for them from the start (sub-plan 03).

What makes it different is **how it decides which times to offer**. Services have different lengths (for example 30, 60 and 90 minutes), and every booking changes what else can fit around it. The app:

- works out available times on the fly from working hours, time off and existing bookings, so once a slot is taken, every time that would overlap it disappears
- scores each possible slot by how much usable time it would waste, such as a 15-minute hole nobody can book
- lets each business choose, with a **strictness slider**, how hard to push for a tightly packed diary versus giving customers the most choice
- has an interactive **scheduling playground**: anyone can book appointments into a sample business and drag the slider to see how the offered times change, with no sign-up
- tracks a **diary efficiency score** over time, showing how much time and how many bookings were lost to awkward gaps, separately from time that was free because the business was quiet
- (later) offers a free **diary health check**: a business uploads an export from its current system and gets an efficiency score for its past diary
- (later) learns from the business's own booking history, so busy times are packed tightly and quiet times stay flexible
- (later) includes a simulator that measures how each setting affects bookings taken and time wasted

## 2. Goals

- A working product that a small business could actually use, deployed with a public demo.
- An interactive playground that shows the core idea in under a minute, with no sign-up.
- A full product demo: a one-click demo login, realistic sample data, and an example business website with the widget embedded.
- Scheduling that stays correct under pressure: no double bookings, correct across clock changes, thoroughly tested.
- Clean, conventional Laravel and Vue code, with CI and automatic deploys.

## 3. Users and main flows

**Visitor** (playground, no account)
- Books appointments into their own copy of a sample business, drags the strictness slider, and watches the offered times and the day's efficiency change.

**Business owner** (in v1, a practitioner who works alone)
- Signs up, which creates their business.
- Sets up services, working hours, time off and booking rules.
- Sees and manages every booking in a calendar, and marks no-shows.
- Copies their booking link or widget code into their own website.

**Staff** (after v1)
- More practitioners, with or without a login of their own, and what the owner lets each of them change.

**Prospective business** (no account, later)
- Uploads an export of its existing appointments and gets a free diary health check.

**Customer** (no account)
1. Opens the business's booking link or widget.
2. Chooses a service (and, once businesses have staff, a practitioner or "anyone available").
3. Picks a date and one of the offered times.
4. Enters their name, email and phone number, and confirms.
5. Receives a confirmation email with links to cancel or reschedule, then a reminder before the appointment.

## 4. Core concepts

Use these names consistently in code, UI and docs.

| Term | Meaning |
|---|---|
| Business | A tenant: one clinic, salon or independent practitioner. All other data belongs to a business. |
| Sandbox | A temporary copy of the sample business, made for one playground visitor and deleted after 24 hours. It's an ordinary business underneath. |
| Current business | The business a request or job is working for. Every query on tenant-owned data is filtered by it, and with none set the query fails. |
| Practitioner | The person whose diary is booked. Hours, time off and bookings belong to the practitioner. In v1 each business has exactly one, the owner; staff come after v1. |
| Service | Something a customer can book. Has a length, optional buffer time before and after it, and a price. In v1 the practitioner offers every active service. With staff, each service is offered by chosen practitioners, who can have their own length, buffers and price for it. |
| Working hours | What a practitioner works on a date: the edited day's hours if there is one, otherwise the working pattern's. Stored as local times, e.g. 09:00–13:00 and 14:00–17:00. |
| Working pattern | A practitioner's repeating hours: a cycle of 1 to 4 weeks, from a start date until an optional end date. Only one is in force on any date. |
| Edited day | A date whose hours were entered by hand, replacing the pattern's for that date. It may have no hours. "Reset to pattern" removes it. |
| Time off | A one-off period when a practitioner isn't available. |
| Booking | A customer's appointment with one practitioner for one service. Blocks the practitioner from its start minus the buffer before, until its end plus the buffer after. |
| Customer | Someone who has booked with a business. No login; identified within a business by email. |
| Free gap | A continuous stretch of time when a practitioner is working and not blocked. |
| Slot | A possible start time for a service, practitioner and date. Slots are calculated, never stored. |
| Slot interval | The step between possible start times, set for the whole business: 10, 15, 20, 30 or 60 minutes. Default 15, aligned to the clock (:00, :15, :30, :45). |
| Booking window | How soon (minimum notice) and how far ahead (maximum advance) customers can book. Set for the business. Default 2 hours and 90 days. With staff, each practitioner can have their own. |
| Waste score | How much usable time a slot would waste if it were booked. |
| Strictness | A business setting from 0 to 100. 0 offers every slot that fits; 100 offers only the least wasteful ones. |
| Relaxation window | How close to an appointment time strictness stops applying, so late bookings aren't turned away. |
| Stranded time | Free time in a gap too short for any service the practitioner offers. Nobody could ever book it. |
| Fragmented time | Free time in a gap that fits shorter services but not the longest one. |
| Missed booking | A search that ended without a booking, on a day when there was enough free time in total for the service but not in one piece. |
| Efficiency score | A 0–100 score showing how much of the time taken up by bookings was actually booked rather than stranded. |

All times are shown to customers in the business's time zone, since appointments are in person.

## 5. How scheduling works

### 5.1 Calculating available slots

For a service, a date range and a practitioner (in v1, the business's only one):

1. Work out the practitioner's working hours for each date (the edited day if there is one, otherwise the working pattern), and turn them into real time ranges using the business's time zone, so 09:00 stays 09:00 when the clocks change.
2. Subtract time off and existing bookings (including their buffers) to get the free gaps.
3. In each free gap, list every start time on the slot interval where the whole service, plus its buffers before and after, fits.
4. Drop start times outside the booking window.
5. Apply the waste score and strictness rules (5.3 and 5.4).
6. With staff (after v1), for "anyone available", combine practitioners: a time is shown if at least one of them can take it.

Example: a practitioner works 09:00–12:00 and has a 30-minute booking at 10:00. The free gaps are 09:00–10:00 and 10:30–12:00. For a 60-minute service the possible starts are 09:00, 10:30, 10:45 and 11:00. 09:15 isn't offered because it would run into the 10:00 booking.

Slots aren't cached at first. A day for a few practitioners is only a few hundred checks, so slots are always calculated from current data, and a new booking immediately changes what's offered.

### 5.2 Making a booking safely

Two customers can try to take the same time at the same moment. Two layers stop a double booking:

1. **Application:** the booking is created in a database transaction that locks the practitioner's row, checks again that the slot is still free, then saves it.
2. **Database:** a PostgreSQL exclusion constraint (using the `btree_gist` extension) refuses any two active bookings for the same practitioner whose times overlap. Even a bug in the application can't create a double booking.

The customer who loses the race sees "Sorry, that time was just taken" along with an updated list of times.

With staff (after v1), an "anyone available" booking goes to the practitioner it fits best: the one with the lowest waste score. This keeps longer free gaps open for longer services.

### 5.3 Waste score

Every possible slot gets a waste score that combines:

- **Stranded time:** any part of the free gap left before or after the booking that's too short for every service the practitioner offers. A 30-minute booking at 09:15, in a gap starting at 09:00, strands 15 minutes.
- **Lost capacity:** how much the booking reduces room for longer services. Splitting a 90-minute gap into two 30-minute pieces scores worse than taking 30 minutes from one end of it.

The exact formula is defined in sub-plan 06, then tuned using the simulator.

### 5.4 Strictness slider

Each business sets its strictness from 0 to 100:

- **0 (loosest):** every slot that fits is offered.
- **In between:** slots above a waste threshold are hidden, so most choices remain.
- **100 (tightest):** only the least wasteful slot in each free gap is offered, so customers always have something to choose.

While the owner drags the slider, the admin screen shows a live preview, e.g. "Customers can choose from 18 start times tomorrow (32 at loosest)".

**Relaxation window:** the nearer an appointment time gets, the less likely it is that someone else will book the time being protected. So strictness stops applying within a set number of hours of the slot (default 48, adjustable). If nobody has booked 09:00 by the day before, 09:15 opens up.

### 5.5 Scheduling playground

A public page, with no sign-up, that shows the core idea in action. Each visitor gets their own sandbox: a temporary copy of the sample business from sub-plan 03 (an independent physio and sports massage therapist, with services from 30 to 90 minutes). Sandboxes are deleted after 24 hours, and visitors never see or affect each other's bookings.

On the page:
- a day timeline showing bookings and free gaps, with stranded time highlighted
- a booking panel: choose a service, see the offered times, and click one to book it
- the strictness slider, with the offered times updating as it moves
- a "show hidden times" toggle that greys out the times strictness is hiding and says why, e.g. "would leave 15 minutes nobody can book"
- each offered time's waste score, on request
- a "how far ahead" control (e.g. two weeks away vs tomorrow) that shows the relaxation window in action
- the day's figures, updated after every booking: booked time, stranded time and an efficiency score
- "Fill randomly", which books a batch of random requests to show how a day fragments at different settings, and "Reset"

The playground uses the real engine, booking rules and database constraint, because a sandbox is just an ordinary business. Nothing in it is throwaway: the timeline grows into the admin calendar, and the slider and preview are reused in the admin settings. Sandbox creation is rate-limited to stop bots filling the database.

### 5.6 Diary efficiency score

This score shows how well each practitioner's time was used. In particular, it separates time lost to awkward gaps from time that was simply free because it was a quiet day.

Every night, for each practitioner and the day just ended, the app records:

| Measure | Meaning |
|---|---|
| Working time | Total working hours that day |
| Booked time | Time spent in appointments |
| Free time | Working time that wasn't booked |
| Stranded time | Free time in gaps too short for any service the practitioner offers |
| Fragmented time | Free time in gaps too short for the longest service |
| Missed bookings | Searches that ended without a booking, on a day with enough free time in total for the service but not in one piece |

These use the same gap calculations as the waste score (5.3), and the playground (5.5) shows the same figures live for its single day. Missed bookings come from the search log, which starts in sub-plan 11. The strictness setting in force that day is stored alongside each record.

**The score (0–100).** This is a starting point, to be refined in sub-plan 14:

> efficiency = booked time ÷ (booked time + stranded time)

It answers the question "of the time bookings took up, how much was earning?" A quiet day with no stranded time still scores 100, so the score reflects how well the scheduling worked, not how busy the business was. Once the app learns from history (5.7), stranded time is weighted by how likely it was to be booked, so waste during quiet periods counts for less.

**On the admin dashboard:**
- the score by week and month as a trend line, marked wherever the strictness setting changed, so the owner can see whether a change helped
- time lost to gaps, in hours and as an estimated value in pounds, based on the average price per minute of the business's services
- missed bookings, with the services and days they happened on
- a breakdown by day of the week (and by practitioner, once there are staff)
- stranded time already in the next seven days, which the owner could still fix by moving a booking

An Artisan command can recalculate the figures for past days. It's also used to build up history for the demo business.

### 5.7 Learning from history (later)

The app works out how often each part of the week ends up booked and how far ahead people book it. It uses past bookings plus the search log, where searches that found nothing suitable show unmet demand. Wasted time is then weighted by how likely it was to be booked:

> waste = minutes wasted × chance those minutes would otherwise have been booked

Busy times get packed tightly, while quiet times stay flexible. It's the same idea as Littlewood's rule from airline revenue management: only turn away a certain booking if the chance of a better use of that time is high enough (see [background](../background.md), section 4). New businesses have no history, so they run on the slider alone. The efficiency score uses the same weighting.

### 5.8 Simulator (later)

An Artisan command fills a period with randomly generated booking requests, using a mix of services, preferred times and how far ahead people book. It runs the same requests under different strictness settings and compares bookings taken, time used and minutes wasted. The results feed the insights dashboard and the README. The simulator also generates booking history for the demo business.

### 5.9 Diary health check from imported data (later)

A business that isn't using the app yet can upload its existing appointments and get an efficiency score for its past diary. It shows them quickly what gaps are costing them, and it shows off the app's analysis without any setup.

**Getting the data in.** The business doesn't have to reformat anything; the app works with what they already have:
- **An export from their current booking system.** Most booking systems can export appointments to CSV or Excel from their reports.
- **A calendar file (.ics)** from Google Calendar, Outlook or Apple Calendar, for people who keep their diary there.
- **The app's template spreadsheet**, for anyone working from a paper diary.

**Import steps:**
1. The user uploads the file.
2. The app detects the columns (start, end or duration, practitioner, service, status, price). The user confirms or corrects the mapping with dropdowns.
3. If the date format is ambiguous, the user confirms it. 03/04 could be 3 April or 4 March, and UK exports put the day first.
4. The user confirms each practitioner's working hours. Exports rarely include them, so the app pre-fills a guess from when appointments usually start and finish.
5. The app shows a preview, then the results: efficiency score, stranded and fragmented time, estimated value lost, and the worst days and times.

Missed bookings can't be worked out from an import, because there's no search log.

**Privacy.** The file is read in the browser, and only the columns the analysis needs are sent to the server. Customer names, emails and phone numbers never leave the user's computer. A check done without an account stores nothing. A business that signs up can keep its imported history, which also gives history learning (5.7) data from day one instead of starting with none.

Later, the same import could bring a business's future bookings across when it switches to the app. That's a separate feature, because it needs customer details.

## 6. Architecture and key decisions

| Decision | Reason |
|---|---|
| One Laravel app with Inertia, Vue 3 and TypeScript | A single codebase and deploy, with no separate API needed for the admin area. |
| Build order: foundations, then the scheduling engine, then the playground, then accounts, admin screens and emails | The engine is the riskiest and most distinctive part, so it's proven first. The data model settles before admin screens are built on it, and there's a shareable demo early. |
| The playground runs on the real foundations. Each visitor's sandbox is an ordinary business that uses the real engine, rules and constraint | Nothing in the demo is throwaway, so the early build order doesn't create rework later. |
| Scheduling code receives the business it works on, rather than reading it from the logged-in user | The same code serves the playground (sandbox from the session), the public booking page (business from the URL) and the admin area (business from the user). |
| Version 1 is for one person who works for themselves: one login and one practitioner per business. Nothing belongs to a login; everything belongs to the business or its practitioner | A smaller first version. Staff, roles and per-practitioner settings can be added later without moving data (sub-plan 03) |
| Multi-tenant from the start: one database, every tenant-owned table has a `business_id`, and queries are scoped to the current business automatically | Adding tenancy later would mean touching every query. Isolation between businesses gets its own tests. |
| PostgreSQL everywhere, including tests | Exclusion constraints and time ranges are PostgreSQL features. Tests on SQLite would miss double-booking bugs. |
| Times stored in UTC; working hours stored as local times plus the business's time zone | Stays correct across clock changes. |
| The customer booking flow is one self-contained Vue component backed by a stateless JSON API | The same flow runs on the hosted booking page and inside the embed iframe. Browsers block cookies inside iframes on other sites, so the flow can't rely on sessions or CSRF tokens. It's protected by rate limiting and spam checks instead. |
| The embed is a small TypeScript loader script that opens the booking flow in an iframe, inline or as a popup | The business's site styles can't clash with ours, and updates ship without the business changing anything. A plain iframe snippet is the fallback for site builders that block scripts. |
| Only `/embed/*` pages may be framed by other sites | The admin area stays protected from clickjacking. |
| Live updates use Laravel Reverb. A public channel per business announces "availability changed for date X", and open pages re-fetch their slots | No personal data goes over the socket, and it works inside iframes. |
| Emails and reminders go through queued jobs and the scheduler | Pages respond quickly, reminders go out on time, and reminders are skipped for cancelled bookings. |
| Complex logic lives in dedicated service and action classes | Controllers stay thin, and the scheduling logic is easy to test on its own. |
| Diary statistics are calculated nightly and stored per practitioner per day | Trend charts stay fast, and there's a permanent record of how each day actually ended up, even after settings change. |
| Every availability search is logged from the first public booking page (11) | Missed bookings and demand learning need this data, and logging early means there's history ready when those features arrive. |
| Imported files are read in the browser, and only the columns needed for analysis are uploaded | Customer personal data, which may be health-related, never reaches the server. |
| Docker for local development | Same PHP and PostgreSQL versions as production, and one command starts everything. |
| Fly.io (London) + Neon PostgreSQL (London region) + Cloudflare R2 for uploads | Cheap and proven. |

## 7. Tech stack

| Area | Choice |
|---|---|
| Backend | Laravel 13 (latest stable), PHP 8.5 |
| Frontend | Inertia, Vue 3 (`<script setup>`), TypeScript |
| Starting point | Official Laravel Vue starter kit (includes auth, Tailwind and shadcn-vue) |
| UI | Tailwind CSS 4, shadcn-vue, lucide icons |
| Database | PostgreSQL, same major version locally, in CI and on Neon |
| Real time | Laravel Reverb + Laravel Echo |
| Queues and scheduling | Laravel queues and scheduler. The queue uses Redis (Upstash) from sub-plan 12, and runs jobs straight away (`sync`) until then |
| Email | Mailpit locally; production provider decided in sub-plan 12 |
| Embed widget | TypeScript, built as its own Vite entry, no framework |
| Testing | PHPUnit, Vitest, Playwright; Infection for mutation testing (scheduling engine only); axe for accessibility checks in Playwright |
| Code style and static analysis | Laravel Pint, Larastan at level 9; Oxlint, Oxfmt and `vue-tsc` (through Vite+) |
| Monitoring | Laravel Nightwatch (EU region): server-side errors, requests, queries, jobs, scheduled tasks and emails |
| AI tooling | Laravel Boost (MCP), shadcn-vue MCP |
| CI/CD | GitHub Actions, deploying to Fly.io |
| Hosting | Fly.io (`lhr`), Neon, Cloudflare R2 |

Exact versions are confirmed in sub-plan 01.

## 8. Environments

### Local (Docker Compose)

| Service | Purpose | Added in |
|---|---|---|
| `postgres` | Development database plus a separate `testing` database | 01 |
| `app` | Nginx + PHP-FPM, code mounted from the host | 01 |
| `vite` | Vite dev server with hot reload | 01 |
| `mailpit` | Catches outgoing emails, viewable in the browser | 01 |
| `scheduler` | Runs the Laravel scheduler (first job: deleting expired sandboxes) | 08 |
| `queue` | Queue worker | 12 |
| `reverb` | WebSocket server | 17 |

The Docker and Fly setup uses a proven pattern:
- a multi-stage Dockerfile (Composer, then the Node build, then PHP-FPM + Nginx managed by Supervisor)
- an entrypoint that caches config at runtime so Fly secrets are available
- migrations run by Fly's `release_command`
- in development, containers that run as the developer's own user and share the code, `vendor` and `node_modules` with the host, as Laravel Sail does. On Windows, development happens inside WSL 2, so the code is on a Linux filesystem

### CI (GitHub Actions)

- On every pull request, every push to `main` and once a week:
  - the `checks` job: the build, Pint, Larastan, PHPUnit on PostgreSQL 18, Oxlint and Oxfmt, `vue-tsc` and Vitest. Playwright joins once end-to-end tests exist.
  - the `audit` job: `composer audit` and `npm audit`.
- `main` only accepts pull requests, and only once `checks` and `audit` pass.
- On `main`, after both pass: the `deploy` job deploys to Fly.io automatically.
- Dependabot keeps the Composer and npm packages, the Docker images and the actions up to date. CodeQL scans the TypeScript and the workflows.

### Production

- Fly.io app in London (`lhr`), Neon PostgreSQL in AWS London, Laravel Nightwatch for monitoring, Cloudflare R2 for logos and other uploads.
- **Nothing queries the database on a timer.** Neon's free plan only covers the database being awake about half the time, and Neon puts it to sleep after 5 minutes without queries, so only real visitors should wake it. Sub-plan 02 has the numbers.
- **Always-on processes**, and how each follows that rule:
  - **Web:** one Machine that suspends after a few minutes without visitors and resumes on the next request. The first visit after a quiet spell wakes the Machine and Neon, and takes about a second longer. Fly's health checks call `/up`, which never touches the database.
  - **Scheduler (08):** its own small Machine running `php artisan schedule:work`, always on. Tasks run hourly or less often where possible, so the database wakes once an hour at most.
  - **Queue worker (12):** Redis on Upstash, not the database queue, whose worker checks for jobs every few seconds and would keep Neon awake. The worker runs alongside the scheduler.
  - **Reverb (17):** decided then. Open WebSocket connections keep a Machine awake, but they don't touch the database.

## 9. Data model (first sketch)

The final schema for each table is set in the sub-plan that creates it.

| Table | Key columns |
|---|---|
| `businesses` | name, slug, time zone, slot interval, minimum notice, maximum advance, strictness, relaxation window, logo, brand colour, sandbox expiry (playground sandboxes only) |
| `users` | business, name, email. One per business in v1; a role once there are staff |
| `practitioners` | business, name. One per business in v1; with staff, also user (nullable), colour, own booking window and active |
| `services` | business, name, length, buffer before, buffer after, price (in pence), active |
| `practitioner_service` (after v1) | which practitioners offer which services, with each practitioner's own length, buffers and price |
| `working_patterns` | practitioner, starts on, ends on (nullable), cycle starts on, 1 to 4 weeks of hours. No two overlap for one practitioner |
| `edited_days` | practitioner, date, hours (possibly none) |
| `time_off` | practitioner, starts at, ends at, reason |
| `customers` | business, name, email, phone |
| `bookings` | business, practitioner, service, customer, starts at, ends at, blocked from, blocked until, status (confirmed, cancelled, completed, no-show), source (playground, booking page, widget, admin), manage token (hashed) |
| `availability_searches` | what was searched, how many slots were offered, and whether it led to a booking |
| `diary_stats` | business, practitioner, date, and that day's working, booked, free, stranded and fragmented minutes, plus the strictness in force |
| `diary_imports` (later) | file type, column mapping, date range, results summary |
| `imported_appointments` (later) | import, practitioner, service or duration, start, end, status, price; no customer details |
| `simulation_runs` (later) | settings and results of each simulator run |

`bookings` has the exclusion constraint described in 5.2. It covers `practitioner_id` and the time range from `blocked_from` to `blocked_until`, for bookings that aren't cancelled.

Child tables reference their parents through composite foreign keys that include `business_id`, so the database refuses rows that mix two businesses (sub-plan 03).

## 10. Testing strategy

- **Availability engine and waste score:** unit tests that take a timetable and check the expected slots. They cover clock-change days, buffers, gap edges, the booking window, strictness levels and the relaxation window.
- **Randomised rule checks for the engine:** hundreds of random diaries, generated from a fixed seed so any failure can be reproduced, checked against rules that must always hold. They're written in plain PHPUnit, with no extra package. They start in 04, and new rules are added in 05 to 07:
  - every offered slot, plus its buffers, fits inside working hours, without overlapping time off or any booking's blocked time
  - every offered slot is inside the booking window
  - raising strictness only ever hides slots, never adds any, so strictness 100 offers a subset of strictness 0
  - at any strictness, every free gap with room for the service still offers at least one time
  - within the relaxation window, strictness has no effect
- **Mutation testing (Infection), on the scheduling engine only:** Infection makes small changes to the engine's code and checks that a test fails each time, which shows the tests would actually catch a bug. It's set up in 04. It needs a coverage driver (PCOV) in the dev image, and its PHPUnit 13 support needs confirming then. It's too slow for the whole app; 04 also decides whether it runs in CI and what minimum score it needs.
- **Diary statistics:** a known diary produces known booked, stranded and fragmented minutes, and a known search log produces the right number of missed bookings.
- **Imports:** sample CSV, Excel and .ics files; column detection; day-first and month-first dates; missing columns; cancelled appointments; calendar files that mix time zones.
- **Feature tests:** every endpoint and policy, plus tenant isolation. One business must never see another's data, and one playground sandbox must never see another's.
- **Double booking:** tests that the database constraint rejects overlapping bookings, and that the customer sees a clear message.
- **Vitest:** Vue components, composables and the widget loader.
- **Playwright:**
  - the playground: moving the slider changes the offered times, and booking updates the timeline and figures
  - the customer booking flow
  - admin setup
  - the widget embedded on another site
  - a booked slot disappearing from a second open browser window
  - every page these tests visit is also checked for accessibility problems with axe (`@axe-core/playwright`)
- Every sub-plan lists its own tests, and it isn't done until they pass in CI.

## 11. Ways of working

**For each sub-plan:**
1. Read this overview and `tracking.md`.
2. Draft the sub-plan with the `draft-sub-plan` skill on a `docs/` branch, and merge it once reviewed.
3. Build it on a branch named `feature/NN-short-name`, with the `build-sub-plan` skill. That skill is written just before building 03, once there are plans to build from.
4. Write the tests, and get them passing.
5. Open a pull request, then merge it.
6. Update `tracking.md`.

**Code conventions** live in `CLAUDE.md`, which is created in sub-plan 01:
- Controllers follow Authorize, Validate, Act, Respond.
- Form Requests for validation; policies for authorisation.
- Business logic goes in service and action classes.
- Enums for statuses and roles.
- Vue uses `<script setup>` with a consistent ordering, plus composables. Pinia only when props, events and Inertia props aren't enough.
- Laravel Boost is used to look up version-specific docs.

**Sub-plan template.** Every file in `sub-plans/` uses these headings. Setup-type sub-plans may use ordered **Steps** in place of Backend and Frontend. Each step's heading starts with the word "Step" and its number, as in `### Step 3: Neon`, so searching for "step 3:" finds it. The numbered items inside a step don't need the word, and are referred to as step 3.2.

```
# NN — Name
## Goal              what this delivers, in one or two sentences
## Depends on        earlier sub-plans
## Scope             in scope / out of scope
## Data model        new or changed tables and constraints
## Backend           routes, controllers, services, jobs, policies
## Frontend          pages, components, composables
## Tests             what must be tested
## Done when         checklist
## Notes             gotchas, open questions
```

## 12. Sub-plans (in build order)

Sub-plan files are named `sub-plans/NN-short-name.md`, e.g. `sub-plans/01-project-setup.md`.

### Phase 1: Foundations

| # | Sub-plan | Delivers |
|---|---|---|
| 01 | Project setup | Laravel + official Vue starter kit; Docker dev environment (PostgreSQL, app, Vite, Mailpit); tests running on PostgreSQL; Laravel Boost; `CLAUDE.md` |
| 01b | WSL development environment | Development moved into WSL 2 on Windows, so the code lives on a Linux filesystem; Windows-only workarounds removed |
| 02 | CI and deployment | GitHub repo; Actions for lint, tests and build; Fly.io (London) + Neon + automatic deploys; a live URL from day one |
| 03 | Core data model | Businesses with automatic tenant scoping; the business's practitioner (one in v1); services; working patterns and edited days; time off; booking settings; factories and a sample business; isolation tests. No admin screens yet |

### Phase 2: Scheduling engine

| # | Sub-plan | Delivers |
|---|---|---|
| 04 | Availability engine | Slot calculation (5.1), including time zones and the booking window; thorough unit tests; randomised rule checks; mutation testing with Infection |
| 05 | Making bookings | Booking action; customers; locking + exclusion constraint |
| 06 | Waste score | Waste score (5.3) in the engine; a day's stranded and fragmented time |
| 07 | Strictness and relaxation | Strictness filter and relaxation window in the engine (5.4); settings stored on the business |

### Phase 3: Playground

| # | Sub-plan | Delivers |
|---|---|---|
| 08 | Scheduling playground | Public page with per-visitor sandboxes (5.5): timeline, booking panel, strictness slider, hidden-time explanations, day figures, fill randomly and reset; Playwright end-to-end tests with axe accessibility checks; first README with a GIF |
| ★ | **Playground milestone** | **The core idea is live and shareable** |

### Phase 4: Full product

| # | Sub-plan | Delivers |
|---|---|---|
| 09 | Accounts and sign-up | Owner sign-up creates the business, its practitioner and the one login; current business taken from the logged-in user |
| 10 | Admin setup | Screens for services, working hours, time off and business settings, including the strictness slider with live preview. Working hours: new hours from a date, editing days and resetting them; bookings outside new hours are flagged |
| 11 | Public booking page | Customer booking component; stateless booking API; hosted page at `/book/{business}`; logging of availability searches; Content Security Policy with `spatie/laravel-csp` |
| 12 | Emails and reminders | Confirmation, cancellation and reminder emails through queues and the scheduler; manage-booking page with cancel and reschedule links; production mail provider |
| 13 | Admin calendar | Day and week views, built from the playground timeline; create, move and cancel bookings; no-shows |
| 14 | Efficiency score | Nightly diary statistics (5.6); efficiency score; missed bookings; dashboard trend chart with strictness changes marked; recalculation command |
| 15 | Demo mode and README | Full product demo: sample business with months of history; a one-click demo login; nightly reset; README with screenshots |
| ★ | **MVP milestone** | **Ready for real businesses** |

### Phase 5: Extras

| # | Sub-plan | Delivers |
|---|---|---|
| 16 | Diary health check (import) | Upload CSV, Excel or .ics; column mapping; working-hours confirmation; efficiency results (5.9); template spreadsheet and sample file; signed-up businesses can keep their history |
| 17 | Live updates | Reverb; booked slots disappear from other open pages instantly |
| 18 | Embeddable widget | Loader script; inline and popup modes; auto-resizing; framing rules; example business website on a separate domain |
| 19 | Learning from history | Demand statistics from bookings, imports and the search log; history-weighted waste score and efficiency score |
| 20 | Simulator | Simulation command comparing strictness settings |
| 21 | Insights dashboard | Busy and quiet heatmap; simulator charts; added to the efficiency dashboard |
| 22 | Final polish | Accessibility; performance; more end-to-end tests; custom domain |

## 13. Out of scope (for now)

- Staff: more than one practitioner or login per business, who offers which services, per-practitioner prices, lengths, buffers and booking windows, roles and permissions, and "anyone available". Designed for in sub-plan 03, added after v1
- Payments and deposits
- SMS reminders
- Google and Outlook calendar sync
- Group classes and capacity-based bookings
- Multiple locations per business
- Travel time between home visits
- Waitlists
- Clinical notes or records of any kind
- Recurring bookings
- Native mobile apps
- Rooms and equipment: a booking only blocks a practitioner's time
- Working hours that run past midnight

## 14. Open questions

| Question | Needed by |
|---|---|
| When to add the custom domain: before links are shared at the playground milestone, or in 22 as planned (`bookalive.io` was unregistered on 2026-10-27) | 08 / 22 |
| Default strictness and relaxation window, and whether to set them provisionally in 07 and tune them once the playground shows the dynamic slots working | 07 / 08 |
| Is rate limiting plus a honeypot enough to protect playground sandboxes and public bookings from bots, or should Cloudflare Turnstile be added? | 08 / 11 |
| Production email provider (e.g. Resend, Postmark, Amazon SES) | 12 |
| Week templates and hours snapshots: saved weeks of hours to apply, and saved copies of a practitioner's hours to restore after experimenting. Decide once a first version of the hours and the dynamic slots can be tried. A template would copy its hours when applied; a snapshot would cover one practitioner. Should a snapshot include strictness? | After v1 |
| Which libraries to use for reading CSV, Excel and .ics files in the browser | 16 |
| Where the example business website lives (GitHub Pages or a second Fly app) | 18 |
