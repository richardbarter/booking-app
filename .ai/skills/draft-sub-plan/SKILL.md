---
name: draft-sub-plan
description: "Drafts or revises a Bookalive sub-plan in docs/planning/sub-plans/: the plan for a piece of work that the user reviews before any of it is built. Use this whenever asked to write, draft, outline, revise or extend a sub-plan or a set of them, such as \"draft 03\", \"write the plans for 04 to 07\", \"update the 05 plan\" or \"plan the next part\", even if the request only gives a sub-plan's number. It covers gathering context, the questions to ask first, design patterns, best practice and security, checking behaviour before relying on it, tests, and a review pass before the plan is handed over. Not for building a plan's code, which follows the plan, or for routine updates to tracking.md."
---

# Drafting a sub-plan

A sub-plan is what gets built. The user reviews it before any code is written, and whoever builds it, maybe in a later session with no memory of this one, follows it closely. A mistake in a plan is cheap to fix now and expensive once it's built. So a good plan is correct, specific enough to build from without guessing, and easy for the user to review.

Work through the sections below in order. Each one says why it matters, so use judgement where a section doesn't fit the plan in hand.

## 1. Gather context

Read before deciding anything, because the plans build on each other and the repo has already settled a lot:

- **`docs/planning/00-overview.md`:** the core concepts (section 4), how scheduling works (section 5), the data model sketch (section 9), the sub-plan template (section 11), this plan's row in the build order (section 12), what's out of scope (section 13) and the open questions (section 14).
- **`docs/planning/tracking.md`:** the current status and the decisions log. Where a decision there differs from older text in the overview, the decision wins, and the plan should fix the overview too.
- **`docs/background.md`** for anything in the scheduling engine (04 onwards).
- **The other sub-plans:** the ones this plan depends on, and any later ones already drafted. The plan has to fit both.
- **The code as it is now.** Plans and docs drift, and the code is the truth. Look at the models, migrations, tests, config and the folders under `app/` and `resources/js/` that the plan touches.
- **`CLAUDE.md`:** its conventions, code layout and key project rules apply to every plan.
- **The Boost skills for the areas the plan covers:** `laravel-best-practices` for backend code, `inertia-vue-development`, `wayfinder-development` and `tailwindcss-development` for pages, `fortify-development` for anything to do with logging in, and `testing-best-practices` for the tests.

## 2. Ask the user's questions first

Some decisions are the user's to make: how the product should behave, what the businesses need, what's in scope, and trade-offs that cost time or money. Others can be answered from the docs, the code or a quick experiment, so answer those yourself rather than asking.

- Ask all the user's questions together, before writing, so the user can answer them in one go rather than being interrupted throughout.
- Give each one a recommendation with a short reason, so the user can accept it, change it or skip it. If the tool for multiple-choice questions is available, use it for questions with clear options, with the recommended option first.
- If a new question comes up while drafting, collect it and ask it with any others at a natural break, rather than one at a time.
- Record the answers in the plan, and add a row to the decisions log in `tracking.md` for anything that's a decision.

The user knows the businesses, and you know the code. Questions with recommendations make the most of both, and keep reviews quick.

## 3. Design

**Match what's there.** New code should look like the code beside it. Start from the conventions in `CLAUDE.md`: Authorize, Validate, Act, Respond controllers; Form Requests and policies; actions in `app/Actions` and services in `app/Services`; the scheduling engine as plain PHP in `app/Scheduling`, given the business it works on; enums for statuses and roles; Eloquent scopes and route model binding; props down and events up in Vue, with composables for shared logic. Check the code for how each one is done here before describing it.

**Consider design patterns.** For each part of the plan, ask whether a well-known pattern fits, and if one does, name it in the plan and say why. Examples that often suit this app:

- value objects for things like time ranges and money, so the rules live in one place
- a strategy, where interchangeable rules plug into the same step
- an interface between the engine and its data, so the engine can be tested without the database
- a pipeline, where data passes through a series of filters in order
- builders or factories for test data

**Don't add a pattern without a reason.** Every interface, layer or abstraction has a cost: more code to read, test and change. Add one when this plan needs it, or when a sub-plan in the overview clearly will and adding it now is cheap. Otherwise, mention the future need in Notes instead. Over-engineering is as much a best-practice failure as a missing check.

**Record the alternatives for big decisions.** When a decision is big and hard to change later, such as how something is stored, how the engine is structured or which package to use, and the right choice isn't obvious, list the options briefly in the plan. Give each one's main trade-off, and say why the chosen one wins. That lets the user decide with the trade-offs in view, and stops a later session from "simplifying" back to an option that was already rejected. If one option is clearly right, a sentence on why is enough. Most plans need this for one or two decisions at most.

**Let the database enforce what it can:** foreign keys, unique and check constraints, and exclusion constraints. Rules that only exist in PHP can be bypassed by a bug.

**Keep to the project's rules:**

- **Tenancy:** every tenant-owned table has a `business_id`. Say how scoping applies and what happens when there's no current business.
- **Time:** times are stored in UTC, repeating rules use local times, and the business's time zone is used when the clocks change.
- **Production:** nothing queries the database on a timer, so Neon can sleep. The app runs on one small Machine, and a merge to `main` deploys.

**Use the overview's names** for concepts in code, UI and docs. If the plan introduces a new concept, add it to the overview's core concepts.

## 4. Check before relying on anything

A plan that assumes how something behaves can send the build down the wrong path, so check:

- **Use Boost's `search-docs`** for every framework or package API the plan relies on, and check the installed version first (`composer show <package>`, or `package.json`).
- **Try the risky parts locally:** database constraints, how Laravel, Carbon or PostgreSQL behave in an edge case, or anything that surprises you. Use the Docker PostgreSQL, a scratch migration or a throwaway test, and delete it afterwards. Say in the plan what you checked and what you found.
- **New dependencies need the user's approval.** Name the package, why it's needed and what else was considered. Use the newest stable version, and record any reason to stay on an older one in `tracking.md`.

## 5. Best practice and security

Go through these for everything the plan adds. Not all apply to every plan, but missing one is the usual way a plan goes wrong:

- **Entry points:** every route or action has authorisation (a policy) and validation (a Form Request), and public ones have rate limits.
- **Tenant isolation:** one business can never see or change another's data, including through route model binding.
- **Personal data:** what's stored and why, and that it stays out of logs and Nightwatch.
- **Queries:** no N+1 queries (eager load), indexes for the queries the plan introduces, and pagination where lists can grow.
- **Concurrency:** what happens when two requests race, and which transactions and locks prevent it.
- **Edge cases and failures:** invalid input, midnight, clock changes, zero-length or overlapping times, empty days, and what the user sees when something fails.
- **Types:** design for Larastan level 9 from the start, with array shapes and typed relations, rather than patching errors later.
- **Migrations:** reversible, and safe to run on production data.
- **Pages** (from 08): keyboard use and accessibility checks with axe.

## 6. Tests

Say what must be tested, and at which level: unit tests for the engine, feature tests for HTTP. Cover the failure modes, not only the happy path. Read the `testing-best-practices` skill.

- Tenant isolation gets its own tests.
- The engine (04 onwards) gets randomised rule checks with a fixed seed, and mutation testing with Infection on `app/Scheduling`.
- Tests run on PostgreSQL, never SQLite.
- Every item under **Done when** should be something a test or a check can prove.

## 7. Write it

- **Use the template** in the overview (section 11). Plans with steps give each main step a heading like `### Step 3: Neon`, so it's easy to search for. The numbered items inside a step stay plain, and are referred to as step 3.2.
- **Be specific where the builder would otherwise have to guess:** table columns, types and constraints; class names and where they go, following the code layout in `CLAUDE.md`; routes; and the signatures of anything other plans depend on, such as the engine's interfaces. Leave the rest of the implementation to the builder. A plan isn't the code.
- **Add a diagram where a picture says it faster,** such as how tables relate or how the steps of a process connect. Write it in Mermaid, in a `` ```mermaid `` code block, and GitHub draws it whenever the file or pull request is viewed. Keep each diagram small, and use the plan's own names in it. `erDiagram` for tables and `flowchart` for processes cover most needs. GitHub shows an error box instead of a diagram it can't read, so keep the syntax simple. A diagram of something simple is just clutter.
- **Explain the reason** for each decision that isn't obvious, in a sentence. Builders and reviewers both need it, and it stops a decision being undone later by someone who didn't know why it was made.
- **Write for the user,** who reviews every plan: British English, plain words and short sentences, with jargon explained the first time it appears. Code keeps the framework's spelling (`authorize`, `color`).
- **Keep the docs consistent.** If the plan changes something in the overview or another sub-plan, change that document on the same branch, and add a row to the decisions log.
- **Keep it about the product.** The repo is public, so no personal, account or billing details.
- **Keep it reviewable.** If a plan grows too big to review in one sitting, or mixes work that could ship separately, suggest splitting it, as 01b was split from 01.

## 8. Review it before handing it over

Read the whole plan again as a sceptical reviewer would, and fix what you find:

- Does it contradict the overview, the decisions log, `CLAUDE.md` or another sub-plan?
- Could someone build it without having to ask anything? Where would they get stuck?
- Is any failure case or edge case missing?
- Is anything over-engineered, or built for a later sub-plan without a clear need?
- Does every **Done when** item have a test or check behind it?
- Is every claim about how something behaves backed by the docs or a local check? Mark anything still unverified as such.

Then summarise for the user: what the plan decides, which answers and decisions it rests on, what you checked and how, and what's still open.

## 9. Branch and pull request

Draft on a `docs/` branch, and open a pull request when the plan, or the set, is ready for review. Update `tracking.md` on the same branch: the plan's status becomes **Planned**, and **Next up** points to what follows. Nothing is built until the user has reviewed and merged the plan.
