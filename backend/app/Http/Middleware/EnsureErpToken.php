<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The key the ERP presents to push graduates to us (spec section 7.1).
 *
 * While no token is configured the endpoint does not exist (404, not 401), so a system that has not
 * been connected to an ERP offers nothing to probe. Comparison is constant-time.
 */
class EnsureErpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('sunates.erp.inbound.token');

        abort_if($expected === '', 404);

        $given = (string) $request->bearerToken();

        if ($given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthenticated.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        return $next($request);
    }
}
