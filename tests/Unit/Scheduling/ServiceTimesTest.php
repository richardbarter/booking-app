<?php

namespace Tests\Unit\Scheduling;

use App\Scheduling\ServiceTimes;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ServiceTimesTest extends TestCase
{
    public function test_has_no_buffers_unless_given(): void
    {
        $service = new ServiceTimes(30);

        $this->assertSame(30, $service->durationMinutes);
        $this->assertSame(0, $service->bufferBeforeMinutes);
        $this->assertSame(0, $service->bufferAfterMinutes);
    }

    public function test_keeps_a_one_minute_length_and_its_buffers(): void
    {
        $service = new ServiceTimes(1, 15, 10);

        $this->assertSame(1, $service->durationMinutes);
        $this->assertSame(15, $service->bufferBeforeMinutes);
        $this->assertSame(10, $service->bufferAfterMinutes);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function lengthsBelowOneMinute(): array
    {
        return [
            'zero' => [0],
            'negative' => [-30],
        ];
    }

    #[DataProvider('lengthsBelowOneMinute')]
    public function test_rejects_a_length_below_one_minute(int $durationMinutes): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 1 minute');

        new ServiceTimes($durationMinutes);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function negativeBuffers(): array
    {
        return [
            'before' => [-1, 0],
            'after' => [0, -1],
        ];
    }

    #[DataProvider('negativeBuffers')]
    public function test_rejects_a_negative_buffer(int $before, int $after): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('buffers can\'t be negative');

        new ServiceTimes(30, $before, $after);
    }

    public function test_blocks_its_buffers_either_side_of_the_appointment(): void
    {
        $service = new ServiceTimes(50, 5, 10);

        $this->assertSame([10_000 - 300, 10_000 + 3600], $service->blockedAround(10_000));
    }

    public function test_its_diary_time_adds_both_buffers_to_the_length(): void
    {
        $service = new ServiceTimes(50, 5, 10);

        $this->assertSame(65, $service->diaryMinutes());
    }

    /**
     * @return array<string, array{ServiceTimes, bool}>
     */
    public static function otherTimes(): array
    {
        return [
            'the same length and buffers' => [new ServiceTimes(30, 5, 10), true],
            'another length' => [new ServiceTimes(35, 5, 10), false],
            'another buffer before' => [new ServiceTimes(30, 0, 10), false],
            'another buffer after' => [new ServiceTimes(30, 5, 15), false],
        ];
    }

    #[DataProvider('otherTimes')]
    public function test_equals_only_the_same_length_and_buffers(ServiceTimes $other, bool $expected): void
    {
        $service = new ServiceTimes(30, 5, 10);

        $this->assertSame($expected, $service->equals($other));
    }
}
