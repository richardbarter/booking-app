# Booking App

## About

An online booking system for small appointment-based businesses (clinics, therapists, salons, groomers). Customers book without an account through a public page or an embeddable widget. What sets it apart is how it chooses which times to offer: it scores each possible slot by how much unbookable time it would leave, and a per-business strictness slider controls how tightly the diary is packed.

**Before starting any work, read [docs/planning/00-overview.md](docs/planning/00-overview.md) and [docs/planning/tracking.md](docs/planning/tracking.md).** Each piece of work has its own sub-plan in [docs/planning/sub-plans/](docs/planning/sub-plans/). Background on the scheduling problem is in [docs/background.md](docs/background.md).

## Commands

Docker is the main way to run the app. The app is at http://localhost:8001 and Mailpit at http://localhost:8026.

- `docker compose up -d`: start `postgres`, `app`, `vite` and `mailpit`
- `docker compose exec app php artisan test --compact`: run the PHP tests (or `php artisan test` on the host)
- `docker compose exec app php artisan ...`: any other Artisan command
- `vendor/bin/pint --dirty`: format changed PHP files
- `composer types:check`: Larastan
- `npm run check`: lint and format check (Vite+: Oxlint and Oxfmt)
- `npm run types:check`: `vue-tsc`
- `npm run test`: Vitest

The containers share the code, `vendor` and `node_modules` with the host and run as the host user, so install packages once, on the host or in the `app` container. On Windows, the host is Ubuntu in WSL 2.

## Conventions

- Controllers follow Authorize, Validate, Act, Respond.
- Form Requests for validation; policies for authorisation.
- Thin controllers, with logic in service and action classes.
- Eloquent scopes; route model binding; enums for statuses and roles.
- Vue: props down, events up, and never mutate props. Reusable logic goes in composables.
- Order inside `<script setup>`: imports, types, constants, reactive state, functions, watchers, lifecycle hooks, side effects.
- Use Pinia only when props, events and Inertia props aren't enough.
- shadcn-vue components are thin wrappers around reka-ui. When one misbehaves, check the reka-ui docs.

## Code layout

Organise code by type, Laravel's and the starter kit's default, with subfolders by area (playground, booking, admin and so on). Don't use feature folders.

**Backend**
- Group classes by area once an area has more than one, as the starter kit does with `Settings/`: `Http/Controllers/Admin/`, `Http/Requests/Admin/`.
- Single-purpose actions go in `app/Actions` (e.g. `CreateBooking`), other service classes in `app/Services`.
- The scheduling engine lives in `app/Scheduling`. It's plain PHP: it's given the business and data it works on, and never reads the request or the logged-in user.

**Frontend** (`resources/js`)
- `pages/` mirrors the routes, with area subfolders such as `pages/playground/` and `pages/admin/`. Inertia finds pages there by name.
- `components/ui/` holds only shadcn-vue's components.
- A component used by one area goes in that area's folder (`components/admin/`). A shared one goes in a folder named after its topic (`components/scheduling/`), even if it's the first. When a second area needs an area's component, move it to a topic folder.
- The top of `components/` keeps the starter kit's components, plus general-purpose building blocks with no topic (like `Heading`).
- Composables follow the same rules.
- Folders are lowercase, component files PascalCase, and tests sit next to the file they test.

## Key project rules

- PostgreSQL only, never SQLite, including in tests.
- Store times in UTC.
- Scheduling code receives the business it works on, rather than reading it from the logged-in user.
- Every tenant-owned table has a `business_id`.

## Workflow

- One sub-plan per branch (`feature/NN-short-name`) and pull request, then update `tracking.md`.
- The repo is public, so docs stay about the product.
