<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Routing\Middleware\ValidateSignature as Middleware;

class ValidateSignature extends Middleware
{
    /**
     * The names of the query string parameters that should be ignored.
     *
     * @var array<int, string>
     */
    protected $except = [
        // 'fbclid',
        // 'utm_campaign',
        // 'utm_content',
        // 'utm_medium',
        // 'utm_source',
        // 'utm_term',
    ];

    /**
     * Handle an incoming request.
     *
     * Pass through if the request carries a valid bearer token, otherwise require a valid signed URL.
     */
    public function handle($request, Closure $next, ...$args)
    {
        if (auth('api')->check()) {
            return $next($request);
        }

        return parent::handle($request, $next, ...$args);
    }
}
