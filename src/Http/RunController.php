<?php

namespace Tey\Docket\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use LogicException;
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

    private function resolve(string $token): Run
    {
        $run = Run::query()->where('token', $token)->first();
        abort_if($run === null || $run->expires_at?->lessThanOrEqualTo(now()) || Gate::denies('view', $run), 404);

        return $run;
    }
}
