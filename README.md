# Booking App

An online booking system for small appointment-based businesses, such as physio clinics, sports massage therapists, salons and dog groomers. Customers book from a public page or a widget on the business's own website, with no account needed. It decides which times to offer by scoring each slot by how much unbookable time it would leave behind, so diaries stay tightly packed without turning customers away.

**Status:** in development.

## Running it locally

You need Docker.

```bash
cp .env.example .env
docker compose run --rm app php artisan key:generate
docker compose up -d
```

- App: http://localhost:8001
- Mailpit (catches outgoing email): http://localhost:8026

Run the tests with `docker compose exec app php artisan test` and `npm run test`.

## Docs

- [Background](docs/background.md): the scheduling problem and the research behind the approach
- [Planning](docs/planning/): the overview, progress tracking and a sub-plan for each piece of work
