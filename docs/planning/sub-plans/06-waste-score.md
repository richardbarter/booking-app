# 06 — Waste score

## Goal

Give every time the engine offers a **waste score**: how much room booking it would cost the bookings still to come. It's in minutes, and made of parts that can be explained ("leaves 15 minutes nobody can book", "leaves no room for a 90-minute massage"). Also work out each day's stranded and fragmented time. The strictness slider (07) uses the scores to decide which times to hide. The playground (08) shows them, and the efficiency score (14) uses the day's figures for past days.

## Depends on

04 (availability engine): the slots, free gaps and start times this plan scores. 05 (making bookings): bookings are blocked time, so the free gaps reflect them. 03: the `services` table, which gains a room weight here.

## Scope

**In scope**
- The waste score for each slot, with its parts, and how it compares with the best time in the same free gap
- Each day's stranded and fragmented time
- A **room weight** on each service: how much the score protects room for it
- `AvailabilityService` loads the services the practitioner offers
- Example unit tests, new randomised rules, and updates to the overview and `tracking.md`

**Out of scope**
- Hiding times (07): this plan only scores them
- The screen where the owner sets room weights (10). Until then every service has the default
- Weights learned from history (19), and tuning the formula with the simulator (20)
- Storing day figures each night (14)

## Decisions

The user's answers to this plan's questions (2026-11-02):

| Question | Answer |
|---|---|
| What the waste score is for | Looking forward, before anyone books: how much a time would cost the diary. It isn't stored. Looking back at how a day went is the efficiency score (14), which uses this plan's stranded and fragmented time for past days |
| A time in the middle of a free stretch that costs no room, but splits it | **A small preference for the edges.** At the tightest setting, bookings are packed together from the edges of each free stretch. Loosening the slider brings these middle times back first, before any time that really wastes room |
| How the business's services count | **A weight for each service**, for how often it's booked, the same for every service by default. A rarely booked long service then stops restricting everyone else's times. The owner sets it in plain words on 10's service screen. Learning from history (19) can suggest weights later, from the share of searches for each service rather than raw bookings, since long services get squeezed out and look rarer than they are |

The user's idea (2026-11-02): weights should reflect how often each service is wanted, as in "90% of bookings are 30-minute ones, so a 90-minute service shouldn't block so much".

## Design

### What the score measures

When a customer books a time, the free gap it sits in (its **free stretch**) is split into what's left before it and what's left after. Here a "free gap" is the engine's free gap from 04: time the practitioner is working and not blocked. The score adds up three parts, all in minutes:

1. **Stranded minutes.** The minutes left before or after the time, in pieces too short for any active service (whatever its room weight). Nobody could ever book them. A 30-minute booking at 09:15, in a gap from 09:00, strands 15 minutes.
2. **Lost room.** For each kind of appointment the practitioner offers, how many fewer of them the gap can still take, valued at their length. This is averaged over the kinds, weighted by each one's room weight (see the formula below). It's what makes splitting a 90-minute gap into two 30-minute pieces worse than taking 30 minutes from one end: the first leaves no room for a 60 or a 90, and the second leaves room for a 60.
3. **Splitting a gap.** 5 minutes when the time leaves free time on both sides, rather than sitting against an edge of its gap. An edge is the start or end of working hours, a booking, or time off. This is the small preference for the edges. Two pieces can't hold the same mixes of appointments as one piece of the same total, which the counts per kind of appointment don't see. For example, after a follow-up at 09:00, the stretch 09:30–13:00 holds a 90-minute massage and two assessments. After one at 09:30, the pieces 09:00–09:30 and 10:00–13:00 don't.

**The waste score** is the sum of the three, rounded to whole minutes. **Extra waste** is how much more a time's score is than the lowest score among the times offered in the same gap. The best time in each gap has extra waste 0. Strictness (07) compares extra waste, because part of every booking's score can't be avoided: a booking always takes room, wherever it goes.

**The score depends only on the diary**, not on when someone looks, so the same time always gets the same score. Extra waste is measured against the times actually offered (inside the booking window), so the least wasteful time a customer can still choose is always the best in its gap.

**Kinds of appointment.** Services with the same length and buffers take the same diary time, so they count as one kind, with their room weights added together. For example, two 30-minute services with no buffers, both at the default weight, are one kind with weight 20.

### The formula

For a slot whose blocked time is `B`, in free gap `G`, let `L` be the part of `G` before `B` and `R` the part after. For each kind of appointment `k`:
- `fits(k, P)` means some start time on the slot interval puts `k`'s blocked time entirely inside piece `P` (the same test the engine uses for slots).
- `count(k, P)` is the most appointments of kind `k` that fit in `P` without overlapping, on the slot interval, with buffers adding up back to back.

Then:
- `stranded = minutes(L) if no kind fits in L, plus minutes(R) if no kind fits in R`
- `lost(k) = count(k, G) − count(k, L) − count(k, R)`
- `lost room = Σ weight(k) × length(k) × lost(k) / Σ weight(k)`, over kinds with a weight above 0, and 0 if there are none
- `split = 5 if L and R are both non-empty, else 0`
- `waste = stranded + round(lost room) + split`, rounding halves up with integer arithmetic (`intdiv(2 × numerator + denominator, 2 × denominator)`), so there are no floating-point surprises

`count(k, P)` takes the earliest start that fits, then the earliest after that one's blocked time ends, and so on. For appointments that are all the same length, taking the earliest each time always finds the most that fit. That's the standard result for scheduling equal-length intervals, and a randomised rule checks it against trying every combination.

**In words:** `Σ` means "add up over every kind of appointment", so lost room is a weighted average. For each kind, multiply how many fewer fit by the appointment's length and by its room weight. Add those up, then divide by the total of the room weights. Dividing keeps the result in minutes however many services a business has. For example, in a gap from 09:00 to 10:30 with 30, 60 and 90-minute services all weighted 10, a booking at 09:30 leaves room for one fewer of each. Its lost room is (10 × 30 × 1 + 10 × 60 × 1 + 10 × 90 × 1) ÷ (10 + 10 + 10) = 60.

`lost(k)` is never negative: `L` and `R` are disjoint parts of `G`, so anything that fits in them fits in `G`.

**The weights for the stranded minutes and the lost room are both 1, and the split costs 5 minutes.** These are a first version, kept as named constants in `WasteScorer`. The playground (08) will show whether they feel right, and the simulator (20) will tune them.

### Worked examples

These were checked with a throwaway prototype of the formula. The interval is 15 minutes, and the times shown are the start times.

**The overview's example (5.3):** a gap from 09:00 to 10:30, services of 30, 60 and 90 minutes with no buffers, each weighted 10, booking the 30-minute one:

| Start | Stranded | Lost room | Split | Waste | Extra |
|---|---|---|---|---|---|
| 09:00 | 0 | 40 | 0 | 40 | 0 |
| 09:15 | 15 | 70 | 5 | 90 | 50 |
| 09:30 | 0 | 60 | 5 | 65 | 25 |
| 09:45 | 15 | 70 | 5 | 90 | 50 |
| 10:00 | 0 | 40 | 0 | 40 | 0 |

**The sample business's morning:** free from 09:00 to 13:00, booking a physio follow-up. The three kinds are the follow-up (30 minutes, weight 10), the assessment (60 minutes, weight 10) and the 90-minute massage (weight 5, as it's booked less often), all with no buffers (03).

| Extra waste | Start times | Why |
|---|---|---|
| 0 | 09:00, 12:30 | Against an edge, leaving one piece |
| 5 | 09:30, 10:30, 11:00, 12:00 | Leave whole half-hours on both sides, so they cost no room, but they split the morning |
| 23 | 10:00, 11:30 | Leave room for one fewer 90-minute massage |
| 32 | 09:15, 12:15 | Strand 15 minutes |
| 35 | 10:15, 11:15 | Leave pieces with an odd 15 minutes in them, and room for one fewer 90-minute massage |
| 41 | 10:45 | Split the morning into two pieces of 1h 45, leaving room for one fewer follow-up and one fewer assessment |
| 59 | 09:45, 11:45 | Leave 45 minutes that only fits one follow-up, and room for fewer assessments and massages |

With the 90-minute massage at the full weight of 10 instead of 5, 10:00 and 11:30 rise from 23 to 35 extra, because they cost room for one. The lower weight is what keeps them offered at higher strictness. That's the behaviour the user asked for, for a service that's booked less often.

### Each day's stranded and fragmented time

For each date, each free gap is one of:
- **Stranded:** no active service fits, whatever its weight.
- **Fragmented:** some service fits, but not the longest kind with a room weight above 0, where the longest is the one taking the most diary time. A gap is never fragmented if every weight is 0.
- **Usable:** the longest such kind fits.

The day's stranded and fragmented minutes are the totals of those gaps. This is the overview's definition (sections 4 and 5.6), with the longest service now meaning the longest one the owner wants room kept for. Buffers are planned time, not waste: they're blocked, so they're never part of a free gap.

### Alternatives considered for the score

| Option | Trade-off |
|---|---|
| **Stranded minutes, lost room for each kind weighted by how often it's booked, and a small cost for splitting a gap (chosen)** | Ranks the overview's example and the sample morning sensibly, can be explained piece by piece, and needs no history. The weights let an owner stop a rare long service restricting everyone else |
| Stranded minutes only | The simplest, but it can't see long appointments being squeezed out: in the overview's example, booking the middle of the gap would score the same as booking an end |
| Prefer times next to an existing booking or an edge, and nothing else (like some booking systems' "reduce gaps" setting) | A simple rule, but it gives no graded score for a slider, and it can't tell a harmless gap from one that strands time |
| Work out how many appointment minutes the leftover pieces could hold in the best mix (a packing calculation) | Measures newly stranded time exactly, but it's slower, harder to explain, and on its own it still doesn't protect room for long appointments |
| Weight wasted time by how likely it is to be booked (bid prices, Littlewood's rule; `background.md`, section 4) | The best in principle, but it needs demand data, so it belongs to learning from history (19) |

## Data model

**`services`** gains one column:

| Column | Type | Rules |
|---|---|---|
| `room_weight` | smallint | default 10; check: 0 to 10. How much the waste score protects room for this service: 10 fully, 0 not at all |

Existing services get 10, so nothing changes until an owner sets a weight. `CreateSampleBusiness` (03) gives the sample business's 90-minute massage a weight of 5, since it's booked less often, and leaves the follow-up and the assessment at 10. 10 decides the plain-word levels shown to owners (for example Often, Sometimes, Rarely and Don't keep room), and the number each one stores. The migration's `down()` drops the column.

## Backend

### The engine (`app/Scheduling`)

New value objects, `final readonly` like 04's:

| Class | Holds |
|---|---|
| `OfferedService` | a `ServiceTimes` and a `roomWeight` from 0 to 10 |
| `WasteScore` | `strandedMinutes`, `lostRoomMinutes`, `splitMinutes`, `minutes` (their sum), `extraMinutes`, and `lostRoom`: for each kind of appointment, its `ServiceTimes` and how many fewer fit, for explanations |
| `GapSummary` | the day's `stranded` and `fragmented` free gaps (`list<TimeRange>`), with `strandedMinutes()` and `fragmentedMinutes()` |

**Changes to 04's classes:**
- `Diary` gains `offeredServices`, a non-empty `list<OfferedService>`. In v1 that's every active service. With staff, it's the services the practitioner offers.
- `Slot` gains `waste`, a `WasteScore`.
- `DayAvailability` gains `gapSummary`, a `GapSummary`.
- `SlotFinder::find()` keeps its signature. Scoring is a new step after the booking window, as 04's diagram showed.

**New classes:**
- `ServiceFit` does the fitting and counting: whether a kind of appointment fits in a piece, and how many fit. It uses the date's start times on the slot interval, from `LocalClock::startTimesOn()`, as `SlotFinder` does. So clock changes and 30-minute time zones work the same way as for slots.
- `WasteScorer` scores a day's slots and builds its `GapSummary`. It holds the constants (`SPLIT_MINUTES = 5`), merges kinds with the same times by adding their weights, and works out each gap's counts once rather than for every slot.

Minutes are whole minutes of each piece (`intdiv` of its seconds by 60). The counting works in integer seconds internally, for speed. A month for the sample business means about 30 days × 2 gaps × 15 slots × 3 kinds × 3 pieces, which is small, but Carbon objects in the inner loop would make it slow.

### `AvailabilityService` (04 and 05)

A sixth query loads the business's active services, reading only their length, buffers and room weight, and builds the `Diary`'s offered services. In v1 the practitioner offers every active service; this is the rule 04 asked to keep in one place, so `practitioner_service` can replace it when there are staff. The query count stays the same for any range (now six).

### `availability:show` (04)

It also prints each offered time's waste and extra waste, and the day's stranded and fragmented minutes. That way the scores can be checked by eye on the sample business.

## Frontend

None.

## Tests

As 04: engine tests extend `PHPUnit\Framework\TestCase`, method names start with `test_`, and files mirror `app/Scheduling` under `tests/Unit/Scheduling/`.

**Unit: `ServiceFit`**
- **Fitting:**
  - a kind fits when a start time on the interval puts its blocked time, buffers included, inside the piece
  - it doesn't fit when the piece is long enough but no start time on the interval works, such as a 30-minute service in 10:07–10:40 with a 15-minute interval
- **Counting:**
  - back to back, buffers add up: a 4-hour piece holds five 30-minute appointments with 15 minutes after each
  - a kind with a buffer before needs it inside the piece too
  - an empty piece holds nothing
- **Clock changes:** counting works on a date when the clocks change, using that date's start times.

**Unit: `WasteScorer`**
- **The worked examples:** the overview's example and the sample morning give the tables above, waste and extra waste for every start time.
- **Weights:**
  - with the 90-minute massage weighted 10 instead of 5, 10:00's extra waste is 35 rather than 23
  - with every weight 0, lost room is 0 and nothing is fragmented
  - services with the same times count as one kind, with their weights added together
- **The split:** a slot that fills its gap exactly has no stranded minutes, no split and no leftover pieces; one against one edge has no split; one with free time on both sides has a split of 5.
- **Rounding:** halves round up, using integer arithmetic.
- **Extra waste** is measured against the offered times only. With today's earlier times inside the minimum notice, the best remaining time in the gap has extra waste 0.
- **`GapSummary`:**
  - a 30-minute gap with a 45-minute shortest kind is stranded
  - a 90-minute gap with a 2-hour longest weighted kind is fragmented
  - a 2-hour gap is usable
  - a gap that only a weight-0 service fits isn't stranded, and the longest weight-0 kind doesn't count as the longest

**Unit: randomised rules**, added to 04's `SlotFinderRulesTest`. The generator also makes 1 to 6 offered services, including the requested one, with random room weights, some 0.
1. Every slot's waste parts are 0 or more, and its `minutes` is their sum.
2. In every free gap that has slots, at least one slot has extra waste 0, and none is below 0.
3. A slot's waste doesn't depend on now. Running the same scenario with now three days earlier gives the same `minutes` for every slot offered both times.
4. **Stranded minutes match a brute-force check.** For each piece left by a slot, it tries every clock time on the interval and every active service, using `LocalClock`, and the piece is stranded exactly when nothing fits.
5. **Counts match a brute-force check.** On pieces of up to 4 hours, `count(k, P)` equals the most that fit, found by trying every combination, and `count(k, L) + count(k, R)` is never more than `count(k, G)`.
6. **Each day's summary matches the definitions:**
   - every free gap is in at most one of `stranded` and `fragmented`
   - the minutes are the totals of those gaps
   - a gap is stranded exactly when the brute-force check finds no active service that fits

**Feature: `AvailabilityService`**
- With the sample business at 04's fixed time, Monday 9 November's follow-up times have the sample morning's extra waste.
- Setting the 90-minute massage's `room_weight` to 10 changes 10:00's extra waste from 23 to 35.
- An inactive service doesn't count when scoring.
- The query count is the same for 1 date and 60 (six).

**Feature: database rules**
- `room_weight` defaults to 10 and refuses values outside 0 to 10.

**Mutation testing:** the new code is in `app/Scheduling`, so Infection covers it, and the score stays at 90% or more.

## Done when

- [ ] Every test above passes in CI on PostgreSQL 18, with Pint and Larastan at level 9 clean, and Infection at 90% or more
- [ ] `php artisan availability:show 1 <follow-up's ID> --date=<a Monday next week>` shows the sample morning's extra waste locally
- [ ] Measured locally: `AvailabilityService` for 31 days of the sample business takes under 200 ms, recorded in `tracking.md`
- [ ] `tracking.md` marks 06 as done

## Notes

**A first version, to be tuned.** The formula's shape follows the user's answers, but its constants (the split's 5 minutes, and stranded minutes and lost room counting equally) are a starting point. The playground (08) shows the parts for each time, so they can be judged by eye. Learning from history (19) and the simulator (20) can then replace guesses with data.

**Why it works this way.** These points came out of discussing the design with the user (2026-11-02). They're recorded so a later change doesn't undo them by accident:
- **The customer chooses a service first, then sees its times** (overview, section 3). So each search scores the times for one service only. The score still weighs the room left for every service, because it's about the bookings still to come on that day. One practitioner has one booking at a time, so "room" always means later bookings, never simultaneous ones.
- **Some waste can't be avoided.** When the lengths don't combine, such as 45 free minutes and a 30-minute follow-up, every choice leaves something over. Strictness never hides every time in a gap with room, so the best time is still offered. Taking that booking beats leaving the whole hour empty.
- **Why the edges matter: an example.** The user first discussed this with the original six services, which had 15 minutes of clean-up after each (03's notes). It works the same with today's three. On an empty morning, say a follow-up is booked at 09:30 rather than 09:00. That leaves a 30-minute hole at 09:00. If the next customers want a 90-minute massage and two assessments, only the massage and one assessment fit from 10:00, and the hole stays empty unless another follow-up wants exactly 09:00. Booked at 09:00, all three fit from 09:30, back to back until 13:00. The middle time only wastes nothing if the right customer turns up, so it's offered once the slider loosens a little, not at the tightest setting.
- **The tightest setting is restrictive on purpose.** It can leave only two times on an empty morning. Three things soften this:
  - the default strictness (07) won't be the tightest
  - the relaxation window (07) releases protected room near the date
  - room weights stop a rarely booked long service from restricting everyone else's times
- **Clean-up time belongs in buffers, not in the length.** A "therapy hour" is a 50-minute session with a 10-minute buffer after it, so the customer sees the real length ("50 minutes, ends 10:50") while the diary keeps the full hour. An owner can still set a 60-minute service with no buffer if they prefer; both work, since buffers default to 0 (03). 10's service screen should explain this.

**What the score can't see.** It looks at one gap at a time and counts each kind of appointment separately. So it doesn't value keeping a whole day open for a long appointment, or prefer one gap over another. Strictness (07) works within each gap, so customers still choose between gaps and days freely.

**For later sub-plans**
- **07:**
  - strictness hides times whose extra waste is above a threshold that falls as strictness rises. At 100 the threshold is 0, so only the best times in each gap stay, and every gap with room keeps at least one
  - the relaxation window turns this off near the date
  - strictness by time of day (overview, 5.4) changes which threshold applies, not the score
- **08:**
  - the playground explains a hidden time from its `WasteScore`: "leaves 15 minutes nobody can book" from the stranded minutes, and "leaves room for one fewer 90-minute massage" from the lost room, compared with the best time in the gap
  - its timeline highlights the day's stranded gaps
  - the sample business has no buffers (03), so the timeline is easy to read. A second sample business with buffers is noted in 03 for later
- **10:**
  - the service screen sets `room_weight` with plain-word levels
  - the wording should say what it does, for example "Keep room for this service: Often / Sometimes / Rarely / Don't keep room"
- **14:** the nightly figures use `GapSummary` for the day just ended. A no-show's time is blocked (05), so it isn't free time; 14 decides whether to report it separately.
- **19:**
  - suggest room weights from the share of searches for each service in the search log (11), including searches that found no time
  - don't use raw bookings, since long services get squeezed out and look rarer than they are
