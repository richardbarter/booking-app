<?php

namespace Tests\Unit\Scheduling;

use App\Scheduling\ExtraWaste;
use App\Scheduling\ServiceTimes;
use App\Scheduling\WasteScore;
use PHPUnit\Framework\TestCase;

class ExtraWasteTest extends TestCase
{
    public function test_gives_only_the_stranded_minutes_beyond_the_bests(): void
    {
        $extra = ExtraWaste::between($this->score(stranded: 40), $this->score(stranded: 15));

        $this->assertSame([25, [], false], $this->parts($extra));
    }

    public function test_gives_only_the_room_it_takes_beyond_the_bests_for_each_kind_of_appointment(): void
    {
        $slot = $this->score(lostRoom: [[30, 3], [60, 1], [90, 2]]);
        $best = $this->score(lostRoom: [[60, 1], [30, 1]]);

        $extra = ExtraWaste::between($slot, $best);

        $this->assertSame([0, [[30, 2], [90, 2]], false], $this->parts($extra));
    }

    public function test_tells_kinds_of_appointment_apart_by_their_buffers(): void
    {
        $withBuffer = new ServiceTimes(30, bufferAfterMinutes: 10);
        $slot = new WasteScore(0, 10, 0, 0, [['times' => $withBuffer, 'fewer' => 1]]);
        $best = $this->score(lostRoom: [[30, 1]]);

        $extra = ExtraWaste::between($slot, $best);

        $this->assertSame([['times' => $withBuffer, 'fewer' => 1]], $extra->lostRoom);
    }

    public function test_splits_when_the_best_does_not(): void
    {
        $extra = ExtraWaste::between($this->score(split: 5), $this->score());

        $this->assertSame([0, [], true], $this->parts($extra));
    }

    public function test_does_not_count_a_split_the_best_also_makes(): void
    {
        $extra = ExtraWaste::between($this->score(split: 5), $this->score(split: 5));

        $this->assertSame([0, [], false], $this->parts($extra));
    }

    public function test_leaves_out_the_parts_where_the_slot_does_better_than_the_best(): void
    {
        $slot = $this->score(lostRoom: [[30, 1], [60, 2]]);
        $best = $this->score(stranded: 15, lostRoom: [[30, 2], [60, 1], [90, 1]], split: 5);

        $extra = ExtraWaste::between($slot, $best);

        $this->assertSame([0, [[60, 1]], false], $this->parts($extra));
    }

    public function test_has_nothing_for_the_best_time_itself(): void
    {
        $best = $this->score(stranded: 15, lostRoom: [[30, 2], [60, 1]], split: 5);

        $extra = ExtraWaste::between($best, $best);

        $this->assertSame([0, [], false], $this->parts($extra));
    }

    /**
     * @param  list<array{int, int}>  $lostRoom  lengths of appointments with no buffers, and how many fewer
     */
    private function score(int $stranded = 0, array $lostRoom = [], int $split = 0): WasteScore
    {
        return new WasteScore($stranded, 0, $split, 0, array_map(
            fn (array $kind): array => ['times' => new ServiceTimes($kind[0]), 'fewer' => $kind[1]],
            $lostRoom,
        ));
    }

    /**
     * The stranded minutes, the kinds of appointment as their lengths and
     * how many fewer, and whether it splits the gap.
     *
     * @return array{int, list<array{int, int}>, bool}
     */
    private function parts(ExtraWaste $extra): array
    {
        return [
            $extra->strandedMinutes,
            array_map(fn (array $kind): array => [$kind['times']->durationMinutes, $kind['fewer']], $extra->lostRoom),
            $extra->splits,
        ];
    }
}
