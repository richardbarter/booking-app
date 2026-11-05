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
}
