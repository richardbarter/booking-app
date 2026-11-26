<?php

namespace App\Services\Playground;

use App\Models\Business;
use Carbon\CarbonInterval;
use Illuminate\Contracts\Session\Session;

/**
 * Which sandbox the visitor's session holds (08). It's the only class that
 * knows the session key, and the only way a request finds its sandbox.
 *
 * Only a live sandbox is ever found, so a real business can't be reached
 * even if its ID got into the session. That's the playground's
 * authorisation: there's no user, so there are no policies.
 */
class SandboxSession
{
    private const string KEY = 'playground.sandbox_id';

    public function __construct(private Session $session) {}

    /**
     * The session's sandbox, with its expiry moved forward by the session's
     * lifetime, or null if it has none that's live. An ID with no live
     * sandbox behind it is forgotten.
     *
     * The expiry moves first, in one conditional update, so the clean-up
     * can't delete the sandbox while this request uses it: whichever of the
     * two goes second changes nothing.
     */
    public function current(): ?Business
    {
        $id = $this->session->get(self::KEY);

        if (! is_int($id)) {
            return null;
        }

        $now = now();
        $extended = Business::query()
            ->whereKey($id)
            ->liveSandbox($now)
            ->update(['sandbox_expires_at' => $now->add($this->lifetime())->utc()->format('Y-m-d H:i:sP')]);
        $sandbox = $extended === 1 ? Business::query()->liveSandbox($now)->find($id) : null;

        if ($sandbox === null) {
            $this->session->forget(self::KEY);
        }

        return $sandbox;
    }

    /**
     * How long a sandbox lasts without a visit: the session's lifetime,
     * 120 minutes, so it ends when the session does (08).
     */
    public function lifetime(): CarbonInterval
    {
        return CarbonInterval::minutes(config()->integer('session.lifetime'))->cascade();
    }

    public function remember(Business $sandbox): void
    {
        $this->session->put(self::KEY, $sandbox->id);
    }

    /**
     * Whether the session remembers a sandbox, live or not, so the
     * middleware can tell a visitor whose sandbox expired from a new one.
     */
    public function has(): bool
    {
        return $this->session->has(self::KEY);
    }
}
