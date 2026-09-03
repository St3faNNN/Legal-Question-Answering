<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the operational dashboard: the request must carry an authenticated
 * user whose email is in the config allowlist (see config/admin.php).
 *
 * ## 404, not 403
 *
 * A 403 confirms the route exists and that the caller simply lacks permission,
 * which advertises an admin surface to anyone who guesses a URL. A 404 is
 * indistinguishable from a route that was never defined, so a non-allowlisted
 * user learns nothing. This is the reason the middleware aborts rather than
 * returning a typed error — there is deliberately no error body to read.
 *
 * ## Applied by class name, not by alias
 *
 * Middleware aliases are registered in bootstrap/app.php, which the Docker
 * deploy does not mount (only app/, config/, routes/, database/, resources/ and
 * public/build are). Referencing this class directly in routes/api.php keeps the
 * whole gate inside the mounted tree, so it takes effect on a restart rather
 * than needing an image rebuild.
 *
 * This runs on the DATA endpoints. There is no server-rendered admin page to
 * gate: routes/web.php serves one Blade shell for every path and the SPA routes
 * client-side. The client route is therefore a convenience, not a boundary —
 * everything it would display comes from endpoints this middleware protects.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->isAdmin($request), 404);

        return $next($request);
    }

    /**
     * Whether the request's user is on the allowlist. Case-insensitive: the
     * allowlist is normalised at config load, and the stored email is lowered
     * here, so "Operator@Example.com" in either place still matches.
     */
    public static function isAdmin(Request $request): bool
    {
        $email = $request->user()?->email;

        if ($email === null) {
            return false;
        }

        /** @var list<string> $allowlist */
        $allowlist = config('admin.emails', []);

        // An empty allowlist grants nobody, rather than everybody.
        return $allowlist !== [] && in_array(mb_strtolower(trim($email)), $allowlist, true);
    }
}
