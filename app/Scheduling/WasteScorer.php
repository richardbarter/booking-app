<?php

namespace App\Scheduling;

/**
 * The waste score (06): how much room each slot would cost the bookings
 * still to come, and which of a day's free gaps are stranded or
 * fragmented. It's given the services the practitioner offers. Services
 * with the same times count as one kind of appointment, with their room
 * weights added together.
 *
 * A slot splits its free gap G into what's left before it (L) and after it
 * (R). For each kind k, count(k, P) is how many fit in piece P back to
 * back (ServiceFit), and its score is:
 * - stranded: the minutes of L if no kind fits in it, and of R likewise
 * - lost room: Σ weight(k) × diary(k) × (count(k, G) − count(k, L) −
 *   count(k, R)) ÷ Σ weight(k), over the kinds with a weight above 0,
 *   where diary(k) is the diary time one takes, buffers included. Halves
 *   round up, in whole numbers, so there are no floating-point surprises
 * - split: SPLIT_MINUTES when both L and R have time in them, a small
 *   preference for the edges, since two pieces can't hold the same mixes
 *   of appointments as one piece of the same total
 *
 * The constants are a first version, for the playground (08) and the
 * simulator (20) to tune.
 */
final readonly class WasteScorer
{
    public const int SPLIT_MINUTES = 5;

    private const int SECONDS_IN_MINUTE = 60;

    /**
     * Each kind of appointment, whatever its weight.
     *
     * @var list<ServiceTimes>
     */
    private array $kinds;

    /**
     * Each kind with a room weight above 0, and its weight.
     *
     * @var list<array{ServiceTimes, int}>
     */
    private array $weightedKinds;

    private int $totalWeight;

    /**
     * The weighted kinds that take the most diary time. A gap that fits
     * any one of them is usable.
     *
     * @var list<ServiceTimes>
     */
    private array $longestWeightedKinds;

    /**
     * @param  list<OfferedService>  $offeredServices
     */
    public function __construct(array $offeredServices)
    {
        $kinds = [];

        foreach ($offeredServices as $service) {
            if (! array_any($kinds, fn (ServiceTimes $kind): bool => $kind->equals($service->times))) {
                $kinds[] = $service->times;
            }
        }

        $weightedKinds = [];

        foreach ($kinds as $kind) {
            $weight = array_sum(array_map(
                fn (OfferedService $service): int => $service->times->equals($kind) ? $service->roomWeight : 0,
                $offeredServices,
            ));

            if ($weight > 0) {
                $weightedKinds[] = [$kind, $weight];
            }
        }

        $longest = $weightedKinds === [] ? null : max(array_map(fn (array $kind): int => $kind[0]->diaryMinutes(), $weightedKinds));
        $longestWeightedKinds = [];

        foreach ($weightedKinds as [$kind]) {
            if ($kind->diaryMinutes() === $longest) {
                $longestWeightedKinds[] = $kind;
            }
        }

        $this->kinds = $kinds;
        $this->weightedKinds = $weightedKinds;
        $this->totalWeight = array_sum(array_map(fn (array $kind): int => $kind[1], $weightedKinds));
        $this->longestWeightedKinds = $longestWeightedKinds;
    }

    /**
     * The waste scores of the slots in one free gap, in the order given.
     * Each slot is its blocked time, and the gap its start and end, all in
     * whole seconds, with the gap rounded inwards
     * (TimeRange::wholeSecondsInside()). Extra waste compares each slot
     * with the lowest score among them, so give only the slots that are
     * offered. The gap's counts are worked out once for all of them.
     *
     * @param  list<array{int, int}>  $slots  blocked from and blocked until
     * @return list<WasteScore>
     */
    public function scores(array $slots, int $gapStart, int $gapEnd, ServiceFit $fit): array
    {
        if ($slots === []) {
            return [];
        }

        $gapCounts = array_map(fn (array $kind): int => $fit->count($kind[0], $gapStart, $gapEnd), $this->weightedKinds);
        $parts = array_map(
            fn (array $slot): array => $this->parts($fit, $gapCounts, [$gapStart, $slot[0]], [$slot[1], $gapEnd]),
            $slots,
        );
        $minutes = array_map(fn (array $part): int => $part['strandedMinutes'] + $part['lostRoomMinutes'] + $part['splitMinutes'], $parts);
        $lowest = min($minutes);

        return array_map(
            fn (array $part, int $total): WasteScore => new WasteScore(
                $part['strandedMinutes'],
                $part['lostRoomMinutes'],
                $part['splitMinutes'],
                $total - $lowest,
                $part['lostRoom'],
            ),
            $parts,
            $minutes,
        );
    }

    /**
     * Which of the day's free gaps are stranded and which are fragmented.
     *
     * @param  list<TimeRange>  $freeGaps  in time order
     */
    public function gapSummary(array $freeGaps, ServiceFit $fit): GapSummary
    {
        $stranded = [];
        $fragmented = [];

        foreach ($freeGaps as $gap) {
            [$start, $end] = $gap->wholeSecondsInside();

            if (! self::anyFits($this->kinds, $fit, $start, $end)) {
                $stranded[] = $gap;
            } elseif ($this->longestWeightedKinds !== [] && ! self::anyFits($this->longestWeightedKinds, $fit, $start, $end)) {
                $fragmented[] = $gap;
            }
        }

        return new GapSummary($stranded, $fragmented);
    }

    /**
     * A slot's stranded minutes, lost room in whole minutes, split cost and
     * the kinds it takes room from, named as WasteScore names them.
     *
     * @param  list<int>  $gapCounts  how many of each weighted kind fit in the gap
     * @param  array{int, int}  $before  the piece left before the slot
     * @param  array{int, int}  $after  the piece left after it
     * @return array{strandedMinutes: int, lostRoomMinutes: int, splitMinutes: int, lostRoom: list<array{times: ServiceTimes, fewer: int}>}
     */
    private function parts(ServiceFit $fit, array $gapCounts, array $before, array $after): array
    {
        $stranded = $this->strandedMinutes($fit, ...$before) + $this->strandedMinutes($fit, ...$after);
        $lostMinutes = 0;
        $lostRoom = [];

        foreach ($this->weightedKinds as $index => [$times, $weight]) {
            $fewer = $gapCounts[$index] - $fit->count($times, ...$before) - $fit->count($times, ...$after);

            if ($fewer > 0) {
                $lostMinutes += $weight * $times->diaryMinutes() * $fewer;
                $lostRoom[] = ['times' => $times, 'fewer' => $fewer];
            }
        }

        return [
            'strandedMinutes' => $stranded,
            'lostRoomMinutes' => $this->totalWeight === 0 ? 0 : intdiv(2 * $lostMinutes + $this->totalWeight, 2 * $this->totalWeight),
            'splitMinutes' => $before[0] < $before[1] && $after[0] < $after[1] ? self::SPLIT_MINUTES : 0,
            'lostRoom' => $lostRoom,
        ];
    }

    /**
     * The piece's whole minutes if no kind of appointment fits in it, or 0.
     */
    private function strandedMinutes(ServiceFit $fit, int $from, int $until): int
    {
        return self::anyFits($this->kinds, $fit, $from, $until) ? 0 : intdiv($until - $from, self::SECONDS_IN_MINUTE);
    }

    /**
     * @param  list<ServiceTimes>  $kinds
     */
    private static function anyFits(array $kinds, ServiceFit $fit, int $from, int $until): bool
    {
        return array_any($kinds, fn (ServiceTimes $times): bool => $fit->fits($times, $from, $until));
    }
}
