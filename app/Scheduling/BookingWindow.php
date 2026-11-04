<?php

namespace App\Scheduling;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * When customers can book, as seen from now: no sooner than the minimum
 * notice, in real minutes, and no later than the last bookable date, which
 * is today plus the maximum advance in the business's time zone. Any time
 * on the last date can be booked.
 */
final readonly class BookingWindow
{
    private CarbonImmutable $earliestStart;

    private string $lastDate;

    public function __construct(private BookingSettings $settings, CarbonInterface $now)
    {
        $now = $now->toImmutable()->utc();
        $today = $now->setTimezone($settings->timezone)->format('Y-m-d');

        $this->earliestStart = $now->addMinutes($settings->minNoticeMinutes);
        $this->lastDate = CarbonImmutable::parse($today, 'UTC')->addDays($settings->maxAdvanceDays)->format('Y-m-d');
    }

    public function earliestStart(): CarbonImmutable
    {
        return $this->earliestStart;
    }

    /**
     * The last date customers can book on, as Y-m-d.
     */
    public function lastDate(): string
    {
        return $this->lastDate;
    }

    public function allows(CarbonImmutable $startsAt): bool
    {
        return $startsAt >= $this->earliestStart
            && $startsAt->setTimezone($this->settings->timezone)->format('Y-m-d') <= $this->lastDate;
    }
}
