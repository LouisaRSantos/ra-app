<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after `auth`: signs out deactivated accounts and holds users with a temporary
 * password on the change-password page until they set their own.
 */
class EnsureAccountIsUsable
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson()
                ? response()->json(['message' => 'Account disabled.'], 401)
                : redirect()->route('login')->withErrors(['email' => 'This account is disabled.']);
        }

        if ($user->must_change_password && ! $request->routeIs('password.*')) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Password change required.'], 423)
                : redirect()->route('password.change');
        }

        return $next($request);
    }
}
