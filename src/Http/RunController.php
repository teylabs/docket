<?php

namespace Tey\Docket\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Validation\Rule;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tey\Docket\ItemStatus;
use Tey\Docket\Run;
use Tey\Docket\RunStatus;

/** @internal */
class RunController
{
    public function show(Request $request, string $token): JsonResponse
    {
        $run = $this->resolve($token);
        $user = $request->user();
        $key = 'docket:poll:'.hash('sha256', serialize([$run->id, $user === null ? $request->ip() : [$user::class, $user->getAuthIdentifier()]]));
        $seconds = max(1, (int) ceil(config()->integer('docket.poll_after_ms') / 1000));
        abort_if(RateLimiter::tooManyAttempts($key, 1), 429, '', ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        RateLimiter::hit($key, $seconds);

        return response()->json($run);
    }

    /**
     * Pushes the snapshot as Server-Sent Events: once at the start, then whenever its revision changes. A comment line keeps
     * idle proxies from closing the connection, and the stream ends when the run finishes or after `docket.stream.seconds`,
     * with an `end` event so the client knows to reconnect rather than treat the close as an error.
     */
    public function events(string $token): StreamedResponse
    {
        $run = $this->resolve($token);

        return response()->stream(function () use ($run): void {
            $seconds = max(0, config()->integer('docket.stream.seconds', 60));
            $heartbeat = max(1, config()->integer('docket.stream.heartbeat_seconds', 15));
            $interval = max(250, config()->integer('docket.stream.check_ms', 1000));
            $started = now();
            $lastBeat = now();
            $revision = null;

            $this->send('retry: '.max(1000, config()->integer('docket.poll_after_ms'))."\n\n");

            while (true) {
                $fresh = Run::query()->whereKey($run->getKey())->first();
                if ($fresh === null || $fresh->expires_at?->lessThanOrEqualTo(now()) || Gate::denies('view', $fresh)) {
                    $this->send("event: end\ndata: {\"reason\":\"unavailable\"}\n\n");

                    return;
                }

                if ($fresh->revision !== $revision || $fresh->finished()) {
                    $revision = $fresh->revision;
                    $this->send('id: '.$fresh->revision."\nevent: snapshot\ndata: ".$fresh->toJson()."\n\n");
                }

                if ($fresh->finished()) {
                    $this->send("event: end\ndata: {\"reason\":\"finished\"}\n\n");

                    return;
                }

                if ($started->diffInSeconds(now()) >= $seconds) {
                    $this->send("event: end\ndata: {\"reason\":\"timeout\"}\n\n");

                    return;
                }

                if ($lastBeat->diffInSeconds(now()) >= $heartbeat) {
                    $this->send(": heartbeat\n\n");
                    $lastBeat = now();
                }

                if (connection_aborted() === 1) {
                    return;
                }

                Sleep::usleep($interval * 1000);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'private, no-store',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    private function send(string $frame): void
    {
        echo $frame;

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }

    public function items(Request $request, string $token): JsonResponse
    {
        $run = $this->resolve($token);
        $request->validate(['status' => ['sometimes', Rule::enum(ItemStatus::class)]]);
        $query = $run->items()->orderBy('id');
        if ($request->has('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        $cursor = app(PageCursor::class)->resolve($request, ['id']);

        return response()->json(app(Pages::class)->items($query->cursorPaginate(100, ['*'], 'cursor', $cursor)->withQueryString()));
    }

    public function logs(Request $request, string $token): JsonResponse
    {
        $run = $this->resolve($token);
        $cursor = app(PageCursor::class)->resolve($request, ['id']);

        return response()->json(app(Pages::class)->logs($run->logs()->orderBy('id')->cursorPaginate(100, ['*'], 'cursor', $cursor)->withQueryString()));
    }

    public function children(Request $request, string $token): JsonResponse
    {
        $run = $this->resolve($token);
        $request->validate(['status' => ['sometimes', Rule::enum(RunStatus::class)]]);

        return response()->json(app(Pages::class)->children($run, $request));
    }

    public function cancel(string $token): JsonResponse
    {
        $run = $this->resolve($token);
        abort_unless(Gate::allows('cancel', $run), 403);
        abort_if($run->finished(), 409);

        return response()->json($run->cancel());
    }

    public function retry(string $token): JsonResponse
    {
        $run = $this->resolve($token);
        abort_unless(Gate::allows('retry', $run), 403);
        try {
            $pending = $run->retry();
        } catch (LogicException) {
            abort(409);
        }
        $ticket = $pending->dispatch();
        $response = $ticket->toResponse(request());
        $response->setStatusCode(202);

        return $response;
    }

    public function output(string $token): StreamedResponse
    {
        return $this->stream($this->resolve($token), 'attachment');
    }

    public function view(string $token): StreamedResponse
    {
        $run = $this->resolve($token);

        return $this->stream($run, $run->outputViewable() ? 'inline' : 'attachment');
    }

    private function stream(Run $run, string $disposition): StreamedResponse
    {
        $output = $run->output;
        abort_if($output === null || ! Storage::disk($output['disk'])->exists($output['path']), 404);

        $headers = ['Content-Type' => $output['mime'], 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];

        // An inline image or text file gets no script at all. A PDF can't: browsers refuse to show a sandboxed PDF.
        if ($disposition === 'inline' && $output['mime'] !== 'application/pdf') {
            $headers['Content-Security-Policy'] = 'sandbox';
        }

        return Storage::disk($output['disk'])->response($output['path'], $output['name'], $headers, $disposition);
    }

    private function resolve(string $token): Run
    {
        $run = Run::query()->where('token', $token)->first();
        abort_if($run === null || $run->expires_at?->lessThanOrEqualTo(now()) || Gate::denies('view', $run), 404);

        return $run;
    }
}
