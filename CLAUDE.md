<laravel-boost-guidelines>
=== .ai/project rules ===

# Bookalive

## About

Bookalive is an online booking system for small appointment-based businesses (clinics, therapists, salons, groomers). Customers book without an account through a public page or an embeddable widget. What sets it apart is how it chooses which times to offer: it scores each possible slot by how much unbookable time it would leave, and a per-business strictness slider controls how tightly the diary is packed.

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
- `composer ci:check`: every check CI runs (all of the above, plus a Pint check and PHPUnit). Run it before opening a pull request

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
- Docs and UI text use British English (organise, colour, licence, diary). Code follows the framework's spelling (`authorize`, `color`).

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
- Nothing in production may query the database on a timer, so Neon's free compute can sleep: no database queue, no polling, and health checks stay off the database. Sub-plan 02 explains why.
- Fix the cause of every Larastan error (level 9 from sub-plan 02). No ignore rules, baselines, or casts and `@var` tags that only silence it.
- Use the newest stable versions of tools and packages, not the ones a plan happens to name. Record any reason to stay on an older version in `tracking.md`.

## Workflow

- `main` only accepts pull requests that pass CI (the `checks` and `audit` jobs), so never push to it directly. Every change goes on a branch with its own pull request: `feature/NN-short-name` for a sub-plan, `docs/short-name` for docs-only changes. Pull requests are merged with a merge commit.
- Update `tracking.md` as part of each sub-plan's pull request.
- Once sub-plan 02 is done, merging to `main` deploys to production automatically.
- Check how the framework or a package behaves (in `vendor/`, its docs, or by reproducing it locally) before relying on it in a plan or a fix. Silencing a type error isn't the same as fixing the bug.
- Secrets never go in the repo, in chat, or in a command that lands in shell history. Production secrets live in Fly (`fly secrets import`) and the GitHub `production` environment (`gh secret set`), piped in from standard input.
- The repo is public, so docs stay about the product: no personal, account or billing details.

=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.5. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

</laravel-boost-guidelines>
