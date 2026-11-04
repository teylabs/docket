<?php

namespace Tey\Docket\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tey\Docket\Listing\RunList;

/**
 * The runs-in-progress list: `GET /docket/runs` answers it once, `GET /docket/runs/stream` pushes it as Server-Sent Events
 * whenever it changes. Both list only runs the viewer may view.
 *
 * @internal
 */
class RunListController
{
    public function index(Request $request, RunList $list): JsonResponse
    {
        $filter = $this->filter($request);
        $user = $request->user();
        $key = 'docket:runs:'.hash('sha256', serialize([$filter, $user === null ? $request->ip() : [$user::class, $user->getAuthIdentifier()]]));
        $seconds = max(1, (int) floor(config()->integer('docket.poll_after_ms') / 1000));
        abort_if(RateLimiter::tooManyAttempts($key, 2), 429, '', ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        RateLimiter::hit($key, $seconds);

        return response()->json($list->payload($filter, $user));
    }

    /**
     * Sends the list at once and again whenever a row changes (a new run, a step, a state crossing the slow or timeout threshold,
     * a finished row dropping off), a heartbeat comment while idle, and an `end` event with `timeout` after `docket.stream.seconds`.
     */
    public function events(Request $request, RunList $list): StreamedResponse
    {
        $filter = $this->filter($request);
        $user = $request->user();

        return response()->stream(function () use ($list, $filter, $user): void {
            $seconds = max(0, config()->integer('docket.stream.seconds', 60));
            $heartbeat = max(1, config()->integer('docket.stream.heartbeat_seconds', 15));
            $interval = max(250, config()->integer('docket.stream.check_ms', 1000));
            $started = now();
            $lastBeat = now();
            $last = null;

            $this->send('retry: '.max(1000, config()->integer('docket.poll_after_ms'))."\n\n");

            while (true) {
                $payload = $list->payload($filter, $user);
                // Signed output links change every second; a fresh signature alone is not a change worth sending.
                $rows = array_map(function (array $row): array {
                    $links = is_array($row['links'] ?? null) ? $row['links'] : [];
                    if (is_array($links['output'] ?? null)) {
                        unset($links['output']['url']);
                    }
                    $row['links'] = $links;

                    return $row;
                }, $payload['runs']);
                $signature = hash('sha256', (string) json_encode([$rows, $payload['active']]));

                if ($signature !== $last) {
                    $last = $signature;
                    $this->send("event: runs\ndata: ".json_encode($payload)."\n\n");
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

    /** @return array{name: string, subject_type: string|null, subject_id: string|null, limit: int|null, linger: int|null} */
    private function filter(Request $request): array
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'subject_type' => ['nullable', 'string', 'max:255'],
            'subject_id' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.config()->integer('docket.runs.max_limit', 50)],
            'linger' => ['nullable', 'integer', 'min:0', 'max:'.config()->integer('docket.runs.max_linger_seconds', 3600)],
        ]);

        return [
            'name' => $request->string('name')->toString(),
            'subject_type' => $request->filled('subject_type') ? $request->string('subject_type')->toString() : null,
            'subject_id' => $request->filled('subject_id') ? $request->string('subject_id')->toString() : null,
            'limit' => $request->filled('limit') ? $request->integer('limit') : null,
            'linger' => $request->filled('linger') ? $request->integer('linger') : null,
        ];
    }

    private function send(string $frame): void
    {
        echo $frame;

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
