<?php

namespace Tey\Docket\Trees;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Tey\Docket\Run;

/** @internal */
class Traversal
{
    /** @return Collection<int, Run> */
    public function descendants(Run $run): Collection
    {
        /** @var Collection<int, Run> $descendants */
        $descendants = collect();
        $parents = [$run->id];
        $limit = config('docket.max_depth', 10);

        for ($depth = 0; $parents !== [] && ($limit === null || $depth < $limit); $depth++) {
            $children = Run::query()->whereIn('parent_run_id', $parents)->orderBy('id')->get();
            $descendants = $descendants->concat($children);
            $parents = $children->modelKeys();
        }

        return $descendants;
    }

    /** @return array<string, mixed> */
    public function tree(Run $run, ?Authenticatable $viewer): array
    {
        if (Gate::forUser($viewer)->denies('view', $run)) {
            return [];
        }

        return $this->node($run, $this->descendants($run)->groupBy('parent_run_id'), $viewer);
    }

    /**
     * @param  Collection<string, Collection<int, Run>>  $children
     * @return array<string, mixed>
     */
    private function node(Run $run, Collection $children, ?Authenticatable $viewer): array
    {
        $log = $run->logs()->orderByDesc('id')->first();
        $nodes = [];

        foreach ($children->get($run->id, collect()) as $child) {
            if (Gate::forUser($viewer)->allows('view', $child)) {
                $nodes[] = $this->node($child, $children, $viewer);
            }
        }

        return [
            'id' => $run->id,
            'token' => $run->token,
            'name' => $run->name,
            'status' => $run->status->value,
            'step' => $run->step,
            'progress' => $run->progress->toArray(),
            'counts' => [
                'processed' => $run->succeeded_count + $run->failed_count + $run->skipped_count,
                'succeeded' => $run->succeeded_count,
                'failed' => $run->failed_count,
                'skipped' => $run->skipped_count,
            ],
            'latest_log' => $log === null ? null : [
                'code' => $log->code,
                'params' => (object) ($log->params ?? []),
                'created_at' => $log->created_at->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            ],
            'children' => $nodes,
        ];
    }
}
