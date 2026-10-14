<?php

namespace Tey\Docket\Http;

use Illuminate\Http\Request;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\Gate;
use Tey\Docket\Item;
use Tey\Docket\Log;
use Tey\Docket\Run;

/** @internal */
class Pages
{
    /**
     * @param  CursorPaginator<int, Item>  $page
     * @return array<string, mixed>
     */
    public function items(CursorPaginator $page): array
    {
        return [
            'data' => $page->getCollection()->map(fn (Item $item) => [
                'key' => $item->key,
                'status' => $item->status->value,
                'code' => $item->code,
                'params' => (object) ($item->params ?? []),
                'result' => $item->result,
                'count' => $item->count,
                'finished_at' => $item->finished_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            ])->all(),
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
        ];
    }

    /**
     * @param  CursorPaginator<int, Log>  $page
     * @return array<string, mixed>
     */
    public function logs(CursorPaginator $page): array
    {
        return [
            'data' => $page->getCollection()->map(fn (Log $log) => $this->log($log))->all(),
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
        ];
    }

    /** @return array<string, mixed> */
    public function children(Run $run, Request $request): array
    {
        $cursor = app(PageCursor::class)->resolve($request, ['created_at', 'id']);
        $query = $run->children()->orderBy('created_at')->orderBy('id');
        if ($request->has('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        // Scan bounded database pages until there are 101 visible children.
        // Only visible rows become public cursor boundaries.
        $visible = [];
        $scan = $cursor;
        do {
            $batch = $query->clone()->cursorPaginate(100, ['*'], 'cursor', $scan);
            $rows = $batch->getCollection();
            if ($cursor?->pointsToPreviousItems()) {
                $rows = $rows->reverse();
            }
            foreach ($rows as $child) {
                if (! $child->expires_at?->lessThanOrEqualTo(now()) && Gate::allows('view', $child)) {
                    $visible[] = $child;
                    if (count($visible) === 101) {
                        break;
                    }
                }
            }
            $scan = $cursor?->pointsToPreviousItems() ? $batch->previousCursor() : $batch->nextCursor();
        } while (count($visible) < 101 && $scan !== null);

        $page = new CursorPaginator($visible, 100, $cursor, [
            'path' => $request->url(), 'parameters' => ['created_at', 'id'],
            'query' => $request->except('cursor'),
        ]);

        return [
            'data' => $page->getCollection()->map(function (Run $child): array {
                $ticket = $child->ticket()->toArray();
                $snapshot = $child->toArray();
                $ticket['links']['logs'] = $ticket['links']['poll'].'/logs';

                return [...$ticket,
                    'step' => $snapshot['step'], 'progress' => $snapshot['progress'],
                    'counts' => $snapshot['counts'], 'children' => $snapshot['children'],
                    'latest_log' => $this->log($child->logs()->orderByDesc('created_at')->orderByDesc('id')->first()),
                ];
            })->all(),
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
        ];
    }

    /** @return array<string, mixed>|null */
    private function log(?Log $log): ?array
    {
        return $log === null ? null : [
            'code' => $log->code,
            'params' => (object) ($log->params ?? []),
            'created_at' => $log->created_at->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
