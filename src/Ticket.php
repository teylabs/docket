<?php

namespace Tey\Docket;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use JsonSerializable;
use Tey\Docket\Exceptions\ResultNotAvailable;

/**
 * @api
 *
 * @implements Arrayable<string, mixed>
 */
class Ticket implements Arrayable, JsonSerializable, Responsable
{
    private bool $hasResult = false;

    private mixed $inlineResult = null;

    /** @internal */
    public function withResult(mixed $result): static
    {
        $this->hasResult = true;
        $this->inlineResult = $result;

        return $this;
    }

    public function result(): mixed
    {
        if (! $this->hasResult) {
            throw new ResultNotAvailable('No in-process result is available; queued work finishes later; read $ticket->run()->result once it is finished.');
        }

        return $this->inlineResult;
    }

    /** @internal */
    public function __construct(
        public readonly string $id,
        public readonly string $token,
        public readonly string $name,
        public readonly RunStatus $status,
        public readonly int $revision,
    ) {}

    public function run(): Run
    {
        return Run::findOrFail($this->id);
    }

    /** @return array{schema_version: int, id: string, token: string, name: string, status: string, revision: int, poll_after_ms: int|null, links: array<string, string>} */
    public function toArray(): array
    {
        $poll = url(trim(config()->string('docket.routes.prefix'), '/').'/runs/'.$this->token);
        $links = ['poll' => $poll];
        $run = $this->run();

        if (! $this->status->isFinished() && Gate::allows('cancel', $run)) {
            $links['cancel'] = $poll.'/cancel';
        }

        if (in_array($this->status, [RunStatus::Partial, RunStatus::Failed, RunStatus::Cancelled], true) && Gate::allows('retry', $run)) {
            $links['retry'] = $poll.'/retry';
        }

        return [
            'schema_version' => 1,
            'id' => $this->id,
            'token' => $this->token,
            'name' => $this->name,
            'status' => $this->status->value,
            'revision' => $this->revision,
            'poll_after_ms' => $this->status->isFinished() ? null : config()->integer('docket.poll_after_ms'),
            'links' => $links,
        ];
    }

    /** @return array{schema_version: int, id: string, token: string, name: string, status: string, revision: int, poll_after_ms: int|null, links: array<string, string>} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return $this->toArray();
    }

    /**
     * @internal
     *
     * @param  array{id: string, token: string, name: string, status: string, revision: int}  $data
     */
    public function __unserialize(array $data): void
    {
        $this->id = $data['id'];
        $this->token = $data['token'];
        $this->name = $data['name'];
        $this->status = RunStatus::from($data['status']);
        $this->revision = $data['revision'];
    }

    public function toResponse($request): JsonResponse
    {
        $data = $this->toArray();

        return new JsonResponse($data, $this->status->isFinished() ? 200 : 202, [
            'Location' => $data['links']['poll'],
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
