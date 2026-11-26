<?php

namespace App\Actions\Playground;

use App\Models\Business;

/**
 * Deletes up to 50 expired sandboxes, oldest first, in one query. The
 * database's cascades take everything they own. Starting a sandbox runs it
 * after the response is sent (08); nothing runs it on a timer.
 *
 * The outer condition repeats the expiry test so PostgreSQL checks it again
 * against a row that a visitor's request updated while the delete waited
 * for it. The subquery alone wouldn't be checked again, and would delete a
 * sandbox whose expiry had just moved forward (tried with two sessions).
 * It deletes through the query builder, which gives the count: nothing
 * listens for a business being deleted.
 */
class DeleteExpiredSandboxes
{
    public const int MOST_AT_ONCE = 50;

    /**
     * @return int how many were deleted
     */
    public function handle(): int
    {
        $now = now();

        return Business::query()
            ->expiredSandbox($now)
            ->whereIn('id', Business::query()
                ->select('id')
                ->expiredSandbox($now)
                ->orderBy('sandbox_expires_at')
                ->limit(self::MOST_AT_ONCE))
            ->toBase()
            ->delete();
    }
}
