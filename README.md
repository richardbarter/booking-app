# Bookalive

An online booking system for small appointment-based businesses, such as physio clinics, sports massage therapists, salons and dog groomers. Customers book from a public page or a widget on the business's own website, with no account needed. It decides which times to offer by scoring each slot by how much unbookable time it would leave behind, so diaries stay tightly packed without turning customers away.

[![CI](https://github.com/richardbarter/booking-app/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/richardbarter/booking-app/actions/workflows/ci.yml)

**Status:** in development. The latest `main` runs at https://bookalive.fly.dev, which so far has only the starter kit's pages, with sign-up switched off.

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

## Deployment

Bookalive runs on one Fly.io Machine in London, with its PostgreSQL database on Neon, also in London, and Laravel Nightwatch for monitoring.

- `main` only accepts pull requests, and only once CI's `checks` and `audit` jobs pass. Run `composer ci:check` before opening one: it runs everything `checks` does except the build.
- Merging a pull request deploys it automatically. The `deploy` job in `.github/workflows/ci.yml` runs `flyctl deploy`, Fly builds the image from the Dockerfile's `php` stage, and Fly's release command runs the migrations before the new version starts. The site is down for a few seconds while the only Machine restarts.
- When nobody's visiting, the Machine suspends and Neon's database goes to sleep, so the first visit after a quiet spell takes about a second longer.
- Secrets never go in the repo. The app's secrets (`APP_KEY`, `DB_URL` and `NIGHTWATCH_TOKEN`) live in Fly, and the deploy token (`FLY_API_TOKEN`) lives in the GitHub `production` environment. Settings that aren't secret are in `fly.toml`.

[Sub-plan 02](docs/planning/sub-plans/02-ci-deployment.md) has the full setup, including how to set secrets without them landing in your shell history.

## Docs

- [Background](docs/background.md): the scheduling problem and the research behind the approach
- [Planning](docs/planning/): the overview, progress tracking and a sub-plan for each piece of work

## Licence

Copyright © 2026 Richard Barter. All rights reserved. The code is public so it can be read, but it isn't licensed for reuse.
