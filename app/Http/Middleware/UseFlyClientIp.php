<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UseFlyClientIp
{
    /**
     * Take the client's IP address from Fly-Client-IP.
     *
     * Fly's proxy sets Fly-Client-IP to the address it accepted the connection
     * from. X-Forwarded-For also holds anything the client sent, with the app's
     * own IP on the right, so its entries can't be told apart reliably. Putting
     * Fly-Client-IP in its place gives the trusted proxy settings one address
     * to use. Requests without the header are left alone. Fly replaces any
     * Fly-Client-IP the client sends: in production (sub-plan 02), requests that
     * each forged a different one still hit the rate limit, and the session
     * stored the real address.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $clientIp = $request->headers->get('Fly-Client-IP');

        if ($clientIp !== null) {
            $request->headers->set('X-Forwarded-For', $clientIp);
        }

        return $next($request);
    }
}
