<?php

namespace App\Http\Controllers\Playground;

use App\Actions\Playground\DeleteExpiredSandboxes;
use App\Actions\Playground\StartSandbox;
use App\Http\Controllers\Controller;
use App\Services\Playground\SandboxSession;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

use function Illuminate\Support\defer;

/**
 * The playground's start page, and the Start button that makes a visitor's
 * sandbox (08). Both go to the diary if the session already has a live
 * sandbox, so pressing Start again makes no second one. Two presses that
 * arrive at the same moment can each make one; the session keeps the
 * second, and the first expires unused. There's nothing to validate or
 * authorise: the button sends nothing, and the limiter (throttle:sandboxes)
 * is the only guard.
 */
class SandboxController extends Controller
{
    public function create(SandboxSession $sandboxSession): Response|RedirectResponse
    {
        if ($sandboxSession->current() !== null) {
            return to_route('playground.show');
        }

        return Inertia::render('playground/Start', [
            'lifetime' => $sandboxSession->lifetime()->forHumans(),
        ]);
    }

    /**
     * Expired sandboxes are deleted after the response is sent, so the
     * visitor doesn't wait for it. defer() needs no queue, and reports a
     * failure without it reaching the visitor.
     */
    public function store(SandboxSession $sandboxSession, StartSandbox $startSandbox, DeleteExpiredSandboxes $deleteExpiredSandboxes): RedirectResponse
    {
        if ($sandboxSession->current() === null) {
            $sandboxSession->remember($startSandbox->handle());

            defer(fn (): int => $deleteExpiredSandboxes->handle());
        }

        return to_route('playground.show');
    }
}
