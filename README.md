# Booking App

An online booking system for small appointment-based businesses, such as physio clinics, sports massage therapists, salons and dog groomers. Customers book from a public page or a widget on the business's own website, with no account needed. It decides which times to offer by scoring each slot by how much unbookable time it would leave behind, so diaries stay tightly packed without turning customers away.

**Status:** in development.

## Running it locally

You need a Linux machine with Docker, PHP 8.5 with Composer, and Node 26. On Windows, that means Ubuntu in WSL 2, with Docker Desktop's WSL integration turned on and the code on the Linux filesystem (for example `~/projects`), not on a Windows drive under `/mnt`.

```bash
cp .env.example .env
composer install
npm ci
php artisan key:generate
docker compose up -d
```

- App: http://localhost:8001
- Mailpit (catches outgoing email): http://localhost:8026

The containers share `vendor` and `node_modules` with your machine, which is why it needs to be Linux. They also run as your user, so the files they create are yours. If `id -u` or `id -g` isn't 1000, set `WWWUSER` and `WWWGROUP` in `.env` before starting them.

Run the tests with `docker compose exec app php artisan test` and `npm run test`.

## Docs

- [Background](docs/background.md): the scheduling problem and the research behind the approach
- [Planning](docs/planning/): the overview, progress tracking and a sub-plan for each piece of work
