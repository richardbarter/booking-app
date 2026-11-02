---
name: build-sub-plan
description: "Builds a merged Bookalive sub-plan from docs/planning/sub-plans/: the code, tests and docs it describes, on its own feature branch and pull request. Use this whenever asked to build, implement, start, continue or finish a sub-plan, such as \"build 03\", \"start on 04\", \"carry on with 05\" or \"build the next plan\", even if the request only gives a sub-plan's number. It covers gathering context, branching, building in the plan's order with checkpoints, when to stop and ask, recording deviations, tests, the project's rules, a security and personal data check, a review pass, finishing the pull request, the report, and the checks after the merge. Not for writing or revising a plan, which is the draft-sub-plan skill, or for changes that aren't part of a sub-plan."
---

# Building a sub-plan

A merged sub-plan has been reviewed by the user, and later plans are written against it: they rely on its tables, class names and signatures. So the build follows the plan closely, proves that it works with tests and checks, and says plainly where it had to differ. The user reviews the result as one pull request, and merging it deploys to production. A good build is one the user can review without surprises.

Work through the sections below in order. Each one says why it matters, so use judgement where a section doesn't fit the plan in hand.

## 1. Gather context

Plans are often written before the code they build on, and the code is the truth, so read before writing anything:

- **The plan, all of it:** its Decisions, Design and Notes as well as the parts that say what to build.
- **The plans it depends on, and the later ones already drafted.** The earlier plans' **For later sub-plans** notes say what they left for this one. Later plans rely on what this one creates, such as the engine's signatures, so building something differently can break a later plan without anyone noticing.
- **`docs/planning/tracking.md`:** check that every plan this one depends on is **Done**, and read the decisions log. Where a decision there differs from the plan, the decision wins, as it's newer. If it isn't clear which applies, ask (section 4).
- **`docs/planning/00-overview.md`:** the core concepts (section 4), whose names the code uses, how scheduling works (section 5) and the testing strategy (section 10). **`docs/background.md`** for anything in the scheduling engine (04 onwards).
- **`CLAUDE.md`:** its conventions, code layout and key project rules.
- **The code as it is now:** the models, migrations, tests, config and folders the plan touches. Check that every class, column and method the plan assumes exists, with the name and shape it expects. 04 to 07 were written before 03 was built, for example. Where the code and the plan disagree, find out why before following either.
- **Installed versions and the docs.** Check a package's installed version (`composer show <package>`, or `package.json`) and its docs through Boost's `search-docs` before relying on how it behaves. For anything surprising, read its source in `vendor/` or try it in a throwaway test, as the plans did.
- **The Boost skills for the areas the plan covers:** `laravel-best-practices` for backend code, `inertia-vue-development`, `wayfinder-development` and `tailwindcss-development` for pages, `fortify-development` for anything to do with logging in, and `testing-best-practices` for the tests.
- **Continuing a build?** If the plan's branch already exists, read its commits and the notes in `tracking.md` to see where the build got to.

Only build a plan that's merged into `main`, since a plan still on a `docs/` branch may change in review. If the plan isn't merged, or a plan it depends on isn't done, stop and ask.

## 2. Branch

- **Start from an up-to-date `main`:** `git switch main`, `git pull`, then `git switch -c feature/NN-short-name`, named after the plan's file (`feature/03-core-data-model` for `03-core-data-model.md`).
- **Wait for the previous plan.** Start the next plan only once the previous plan's pull request is merged and its deploy has been checked (section 11). Each plan then builds on code the user has reviewed, and the history stays simple.
- **One sub-plan, one branch, one pull request.** Everything the build changes goes on it, including fixes to the plan, other docs and `tracking.md`. Never push to `main`, which only accepts pull requests that pass CI.
- **Make every commit count.** Pull requests are merged with a merge commit, so every commit on the branch stays in `main`'s history. Make each one a working step, with a message that says what changed and why, in the style of the existing history (`git log`). Once the branch is pushed, add commits rather than rewriting them, so review comments stay attached. If `main` moves on during the build, merge it into the branch rather than rebasing.
- **Mark the plan as started.** In the first commit, set its status in `tracking.md` to **In progress**.

## 3. Build in the plan's order

- **Follow the plan's order:** its `### Step N` headings in a setup plan; otherwise the data model, then the backend in the order of its parts, then the frontend. Later parts build on earlier ones, and it's the order the user reviewed.
- **Follow the plan closely where it's specific:** table and column names, types and constraints, class names and where they go, routes, and the signatures that other plans rely on. Where it leaves the detail to the builder, match the code beside it and the conventions in `CLAUDE.md`. Those details are the builder's to decide, and aren't deviations.
- **Create files with `php artisan make:`**, passing `--no-interaction`, then shape them like their neighbours.
- **Build only what the plan covers.** Anything it leaves to a later plan stays out, even if it would be quick to add now. If a good idea comes up, note it for later in the pull request or `tracking.md` rather than building it.
- **Hand over the commands only the user can run,** such as one that needs `sudo` (04's PCOV install) or the user's own accounts, at the step that needs them. In Claude Code they can run one in the session with `! <command>`.

**Checkpoints.** After each part (a step, the data model, or one part of the backend or frontend):

1. Run the narrowest tests that cover it: a file path or `--filter` with `php artisan test --compact`, in the `app` container or on the host.
2. Run Pint on the changed files (`vendor/bin/pint --dirty --format agent`) and Larastan (`composer types:check`).
3. Once 04 has set up Infection, run `composer test:mutation` if `app/Scheduling` changed.
4. Commit.

A problem is then found close to where it was made, and every commit works. Checkpoints are for checking, not for waiting: carry on to the next part unless something in section 4 applies. If the session may end before the plan is built, say in `tracking.md`'s notes for the next session where the build got to and what's left.

## 4. When the plan doesn't fit

The user reviewed the plan, so a change to it is theirs to approve. Stop and ask when:

- **the plan turns out wrong:** something doesn't behave as it says, the code isn't as it assumes, or following it would break a project rule or a later plan
- **the plan is unclear:** it could be read two ways, and they would build different things
- **a dependency isn't approved:** a Composer or npm package, PHP extension, Docker image or GitHub Action that neither the plan nor the decisions log names
- **a decision isn't in the plan:** anything the user would want a say in, such as how the product behaves, what's stored, the text people see or what's in scope, or anything that costs money or changes production or an account outside the repo

How to ask:

- First answer what you can from the docs, the code or a quick experiment, and decide the builder's details yourself.
- Give each question a recommendation with a short reason, so the user can accept it or change it. If the tool for multiple-choice questions is available, use it, with the recommended option first. If several questions are waiting, ask them together.
- Never work around the problem to keep going: no ignored check, skipped test, hack or temporary fix. That's how a wrong plan ends up built.

**Record each deviation.** When the build differs from the plan, or settles something the plan didn't:

- Change the plan so that it describes what's built, with a sentence on why. Rewrite the text rather than adding a note beside it, as 02 did, because the plan stays the record of how this part works, and later sessions and plans read it. A decision the plan didn't make goes in its **Decisions** table.
- Add a row to the decisions log in `tracking.md`: the date, what was decided, and why.
- Change the overview and any other sub-plan the deviation affects, especially later plans' notes and the signatures they rely on.
- Do all of this in the build's pull request, and list it under the description's deviations (section 9).

A typo or a file name that has moved is simply fixed in the plan, with no row in the log.

## 5. Tests

- **Write the tests the plan lists,** in the files and at the levels it gives. Read the `testing-best-practices` skill first, and follow the tests already in the folder.
- **Write them alongside the code,** in the same checkpoint as the part they cover, not at the end. A test written afterwards tends to describe the code rather than the plan, and a bug found late is harder to trace.
- **Treat the plan's list as the minimum.** If the build adds a decision the plan didn't foresee, such as a branch, a validation or a failure case, test that too. Don't add tests that only repeat others.
- **Check that each new test can fail.** A test that passes first time may test nothing. For the engine, Infection checks this. Elsewhere, break the code it covers for a moment, or check an assertion against a wrong value.
- **Run the narrowest tests while working,** and the whole suite at the end, through `composer ci:check` (section 9).
- **Keep to the plans' testing rules.** The engine's tests (`tests/Unit/Scheduling`) extend `PHPUnit\Framework\TestCase` and need no database or Laravel. Feature tests run on PostgreSQL. The randomised checks run with their fixed seed, and with other seeds when the plan says so.
- **Look at pages as well (from 08).** They get the plan's Playwright tests with axe, and a look in the browser at http://localhost:8001, since a passing test doesn't show whether a page looks right.

## 6. Keep to the project's rules

These come from `CLAUDE.md` and the decisions log, which records why each one was chosen. They hold for every plan, whether or not it repeats them, and breaking one is a deviation, so ask first (section 4):

- **PostgreSQL only,** never SQLite, including in tests.
- **Times in UTC.** Instants are `timestamptz` columns written through the `AsUtcDateTime` cast, and queries use UTC times. Repeating rules use local times, converted in the business's time zone, so clock changes are handled.
- **Tenancy.** Every tenant-owned table has a `business_id`, a foreign key to `businesses` with `ON DELETE CASCADE` and composite foreign keys to its parents, and its model uses `BelongsToBusiness`. 03's guard test checks every table. Scheduling code is given the business it works on, and never reads the request or the logged-in user.
- **Nothing queries the database on a timer** in production, so Neon can sleep: no database queue, no polling, health checks that stay off the database, and scheduled tasks hourly or less often.
- **Larastan at level 9.** Fix the cause of every error: no ignore rules, baselines or `@phpstan-ignore` comments, and no casts or `@var` tags that only silence it. If an error can't be fixed properly, ask.
- **Pint** on every changed PHP file.
- **Infection on `app/Scheduling`,** once 04 has set it up, with a score of at least 90%. When a change escapes, add a test that catches it. Only a change shown to make no difference to behaviour may be ignored, in `infection.json5`, with a comment saying why.
- **The newest stable versions.** When installing an approved package, use its newest stable release, not the one the plan names, and record any reason to stay on an older one in `tracking.md`.
- **British English** in docs and UI text. Code keeps the framework's spelling (`authorize`, `color`).

## 7. Check security and personal data

Before the review, go through everything the branch adds. The tests cover what the plan foresaw, and this catches what it didn't:

- **Entry points:** every route or action has a policy and a Form Request, and public ones have rate limits.
- **Tenant isolation:** new tables are covered by the guard test, route model binding only finds the current business's rows, and `exists` and `unique` rules are scoped by business.
- **Personal data:** only what the plan says is stored, and none of it reaches logs, exception messages, Nightwatch, command output or a public response. Test data and the sample business use made-up details.
- **Input and output:** queries bind their values, mass assignment only allows the fields meant to be set, and pages never use `v-html` with anything a user typed.
- **Concurrency:** the transactions, locks and constraints the plan describes are there, and tested.
- **Secrets:** none in the repo, the pull request or a command that lands in shell history. `.env.example` gets names, never real values.
- **Dependencies:** if any were added, `composer audit` and `npm audit --audit-level=high` pass, as CI's `audit` job needs.
- **The public repo:** no personal, account or billing details in the code, docs, commits or pull request.

Fix what you find, with a test when it changes behaviour.

## 8. Review the diff

Before handing over, review the whole branch as a sceptical reviewer would:

1. Read the diff against `main` yourself (`git fetch`, then `git diff origin/main...HEAD`). Does it do what the plan says, and nothing more? Is there leftover debugging, commented-out code or a `TODO`? Do the names match the overview's? Do the later plans' assumptions still hold?
2. Run the `code-review` skill at the `high` level on the branch, for correctness bugs. Where it isn't available, as in Cursor, step 1 and section 7 are the review.
3. Check each finding against the code before acting on it, as a reviewer can be wrong. Fix the ones that hold up, with a test when it's a bug, and say in the pull request which you didn't fix and why.

The user reviews the pull request too, but a bug found now is cheaper to fix than one found in review, and much cheaper than one found in production.

## 9. Finish

The pull request is what the user reviews, and merging it deploys, so it has to show what was built and the evidence that it works, without the user rerunning anything.

1. **Run the checks CI runs,** after the review's fixes: `composer ci:check`, and `npm run build` too if the frontend changed (CI's `checks` job runs both). They must pass. If one fails, fix the cause, not the check.
2. **Tick each "Done when" item** in the plan, and give its evidence in the pull request description: the test, the command and what it printed, or the measurement. An item that can only be checked after the merge, such as a migration running on Neon, stays unticked, under **After the merge**.
3. **Update `tracking.md`:**
   - the plan's status becomes **Done**. Its PR column adds the build's pull request to the plan's (`Plan #7, build #9`), and its notes give what's worth knowing, such as measurements the plan asked for
   - **Current status** says what the plan delivered, and **Next up** what follows
   - the decisions log has a row for each deviation
   - the development environment list includes any tool the build installed
4. **Push the branch and open the pull request** against `main`. Its number is only known once it's open, so add it to `tracking.md` in a last commit, as #7 did.
5. **Write the description** in the style of the earlier ones (`gh pr view 1`):
   - a first line naming the sub-plan and its file
   - **What's in it:** what was built, by area
   - **Checked:** `composer ci:check`, each "Done when" item with its evidence, the security check and the review pass
   - **Deviations from the plan:** each one, why, and where it's recorded, or "None"
   - **After the merge:** the checks that wait for the merge, and anything the user needs to do
6. **Don't merge it.** The user reviews and merges, and the merge deploys.

## 10. Report

Once the pull request is open, tell the user, briefly:

- the pull request's address, written out in full
- what was built, in a few lines
- what was checked, and anything that failed or was skipped, said plainly
- the deviations, and the answers they rest on
- what's left: the checks after the merge, any command the user still has to run, and anything noted for later plans

The pull request holds the detail. The report tells the user what to look at first.

## 11. After the merge

The merge is the first time the build runs in production, on Neon, so some of the plan's checks can only happen now, and the next plan shouldn't start until they pass. When the user says the pull request is merged:

1. Watch the merge commit's run on `main` (`gh run list --branch main`, then `gh run watch <run-id> --exit-status`): `checks`, `audit` and `deploy`.
2. Check that Fly's release command ran the migrations (`fly releases`, and `fly logs --no-tail` for its output), then run the plan's checks that waited for the merge.
3. Report the results. Record them in `tracking.md`, and tick those items in the plan, on the next branch: the next plan's, or a `docs/` branch if nothing is being built next.
4. If a check fails, say so, and fix it straight away in a pull request of its own. Never change production by hand to make a check pass.
