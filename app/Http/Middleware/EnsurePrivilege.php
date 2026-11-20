<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePrivilege
{
    public function handle(Request $request, Closure $next, string $module, string ...$names): Response
    {
        $user = $request->user();

        abort_unless($user, 401, 'Unauthenticated.');

        foreach ($names as $name) {
            if ($user->hasPrivilege($module, $name)) {
                return $next($request);
            }
        }

        abort(403, 'You do not have permission to do this.');
    }
}
