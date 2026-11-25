<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Mirrors middleware/security.js::sanitizeBody (top-level string fields). */
class SanitizeBody
{
    private const SKIP = [
        'password', 'password_confirm', 'password_confirmation', 'old_password',
        'new_password', 'secret', 'api_key', 'token', 'private_key',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $fields = $request->all();
        foreach ($fields as $key => $value) {
            if (in_array($key, self::SKIP, true) || ! is_string($value)) {
                continue;
            }
            $clean = str_replace(['<', '>'], '', $value);
            $clean = preg_replace('/javascript:/i', '', $clean);
            $clean = preg_replace('/\bon\w+\s*=/i', '', $clean);
            $request->merge([$key => trim($clean)]);
        }

        return $next($request);
    }
}
