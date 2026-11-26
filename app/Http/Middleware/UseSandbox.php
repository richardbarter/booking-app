<?php

namespace App\Http\Middleware;

use App\Services\CurrentBusiness;
use App\Services\Playground\SandboxSession;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the visitor's sandbox the current business, for the playground's
 * pages and writes (08). With none, it sends them to the start page, and
 * says why if they had one that expired or the page was already open.
 *
 * It runs before SubstituteBindings (bootstrap/app.php), so route model
 * binding only finds the sandbox's own rows.
 */
class UseSandbox
{
    public function __construct(
        private SandboxSession $sandboxSession,
        private CurrentBusiness $currentBusiness,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $hadSandbox = $this->sandboxSession->has();
        $sandbox = $this->sandboxSession->current();

        if ($sandbox === null) {
            if ($hadSandbox || $request->inertia()) {
                Inertia::flash('toast', ['type' => 'info', 'message' => $this->endedMessage()]);
            }

            return to_route('playground.start');
        }

        $this->currentBusiness->set($sandbox);

        return $next($request);
    }

    private function endedMessage(): string
    {
        return __('Your playground ended after :lifetime without a visit. Start a new one to carry on.', [
            'lifetime' => $this->sandboxSession->lifetime()->forHumans(),
        ]);
    }
}
