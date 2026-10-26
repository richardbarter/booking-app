# Tracking

Last updated: 2026-10-26

## Current status

Planning. [00-overview.md](00-overview.md), [../background.md](../background.md) and [sub-plan 01](sub-plans/01-project-setup.md) are written.

## Next up

1. Build sub-plan 01 (project setup).
2. Write the next sub-plans from a Claude Code session opened in this project, so Laravel Boost's docs search is available.
3. Choose a product name before sub-plan 02, because it sets the Fly app name and URL.

## Sub-plan status

Status values: Not started, Planned (plan written), In progress, Done.

| # | Sub-plan | Status | PR | Notes |
|---|---|---|---|---|
| | **Phase 1: Foundations** | | | |
| 01 | Project setup | Planned | | |
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

## Notes for the next session

- `D:\projects\booking-app` already contains `docs/` (with `planning/` inside), so `laravel new` and `composer create-project` can't install straight into it. Create the app in a temporary folder and move the files across. **Don't use the Laravel installer's `--force` flag here: it deletes the existing folder, `docs/` included.**
- This repo will be public, so keep the planning docs about the product. Leave out personal or job-search notes.
