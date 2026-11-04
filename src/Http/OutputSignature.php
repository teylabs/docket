<?php

namespace Tey\Docket\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checks an output link's relative signature, like `signed:relative`, but answers a browser that presents an expired or invalid
 * link with a short "This link has expired" page instead of a JSON error. API clients keep Laravel's JSON error.
 *
 * @internal
 */
class OutputSignature
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasValidRelativeSignature()) {
            return $next($request);
        }

        if (RunRequest::wantedHtml($request)) {
            return response()->view('docket::expired-link', [
                'url' => config('docket.expired_link.url') ?? url('/'),
                'label' => config('docket.expired_link.label') ?? 'Back',
            ], 403, ['Cache-Control' => 'private, no-store']);
        }

        throw new InvalidSignatureException;
    }
}
