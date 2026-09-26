<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards /admin: the platform administrator role plus confirmed two-factor authentication (when required).
 * A household owner is not an administrator.
 */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isPlatformAdmin()) {
            abort(403, 'Administrácia je dostupná iba správcovi platformy.');
        }

        if (! $user->canAccessAdmin()) {
            return redirect()
                ->route('security.edit')
                ->with('admin_two_factor_required', true);
        }

        return $next($request);
    }
}
