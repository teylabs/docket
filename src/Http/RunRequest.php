<?php

namespace Tey\Docket\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** @internal */
class RunRequest
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('docket.wanted_html', ! $request->expectsJson() && str_contains((string) $request->header('Accept'), 'text/html'));
        $request->headers->set('Accept', 'application/json');
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /** Whether the request, before Docket asked for JSON, was a browser asking for a page. */
    public static function wantedHtml(Request $request): bool
    {
        return $request->attributes->get('docket.wanted_html') === true;
    }
}
