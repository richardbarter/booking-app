<?php

namespace Tests\Unit\Scheduling;

use App\Scheduling\OfferedService;
use App\Scheduling\ServiceTimes;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OfferedServiceTest extends TestCase
{
    public function test_keeps_full_room_unless_given_a_weight(): void
    {
        $service = new OfferedService(new ServiceTimes(30));

        $this->assertSame(10, $service->roomWeight);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function weightsInRange(): array
    {
        return ['no room kept' => [0], 'full room' => [10]];
    }

    #[DataProvider('weightsInRange')]
    public function test_accepts_a_weight_from_0_to_10(int $roomWeight): void
    {
        $service = new OfferedService(new ServiceTimes(30), $roomWeight);

        $this->assertSame($roomWeight, $service->roomWeight);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function weightsOutOfRange(): array
    {
        return ['below 0' => [-1], 'above 10' => [11]];
    }

    #[DataProvider('weightsOutOfRange')]
    public function test_rejects_a_weight_outside_0_to_10(int $roomWeight): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("A room weight must be from 0 to 10, got {$roomWeight}.");

        new OfferedService(new ServiceTimes(30), $roomWeight);
    }
}
