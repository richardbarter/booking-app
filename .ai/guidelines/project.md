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

`vendor` and `node_modules` live in container volumes. After changing dependencies on the host, also run `docker compose exec app composer install` or `docker compose exec app npm install`.

## Conventions

- Controllers follow Authorize, Validate, Act, Respond.
- Form Requests for validation; policies for authorisation.
- Thin controllers, with logic in service and action classes.
- Eloquent scopes; route model binding; enums for statuses and roles.
- Vue: props down, events up, and never mutate props. Reusable logic goes in composables.
- Order inside `<script setup>`: imports, types, constants, reactive state, functions, watchers, lifecycle hooks, side effects.
- Use Pinia only when props, events and Inertia props aren't enough.
- shadcn-vue components are thin wrappers around reka-ui. When one misbehaves, check the reka-ui docs.

## Key project rules

- PostgreSQL only, never SQLite, including in tests.
- Store times in UTC.
- Scheduling code receives the business it works on, rather than reading it from the logged-in user.
- Every tenant-owned table has a `business_id`.

## Workflow

- One sub-plan per branch (`feature/NN-short-name`) and pull request, then update `tracking.md`.
- The repo is public, so docs stay about the product.
