<?php

namespace Tey\Docket\Protection;

use Closure;
use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use ReflectionFunction;
use SplObjectStorage;
use Tey\Docket\Reporting\Values;

/** @internal */
class Fingerprint
{
    public function job(object $job): string
    {
        return hash('sha256', serialize(method_exists($job, 'docketKey')
            ? ['key', Values::key($job->docketKey())]
            : [$job::class, $this->normalize($job, new SplObjectStorage)]));
    }

    public function inline(string $name, Closure $callback): string
    {
        $reflection = new ReflectionFunction($callback);

        return hash('sha256', serialize([$name, $reflection->getFileName(), $reflection->getStartLine()]));
    }

    /** @param SplObjectStorage<object, mixed> $seen */
    private function normalize(mixed $value, SplObjectStorage $seen): mixed
    {
        if ($value instanceof Model) {
            return ['model', $value::class, $this->modelKey($value->getKey())];
        }

        if ($value instanceof ModelIdentifier) {
            return ['model', $value->class, is_array($value->id) ? array_map($this->modelKey(...), $value->id) : $this->modelKey($value->id)];
        }

        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return ['array', array_map(fn ($entry) => $this->normalize($entry, $seen), $value)];
        }

        if (is_object($value)) {
            if ($seen->offsetExists($value)) {
                return ['reference', $seen[$value]];
            }

            $seen[$value] = count($seen);

            if ($value instanceof Closure) {
                $reflection = new ReflectionFunction($value);

                return ['closure', $reflection->getFileName(), $reflection->getStartLine(), $this->normalize($reflection->getStaticVariables(), $seen)];
            }

            if (method_exists($value, '__serialize')) {
                return [$value::class, $this->normalize($value->__serialize(), $seen)];
            }

            if ((new \ReflectionClass($value))->isInternal()) {
                return [$value::class, serialize($value)];
            }

            return [$value::class, $this->normalize((array) $value, $seen)];
        }

        if (is_resource($value)) {
            throw new InvalidArgumentException('Job input must be serializable.');
        }

        return $value;
    }

    private function modelKey(mixed $key): ?string
    {
        if ($key === null || is_string($key)) {
            return $key;
        }

        if (is_int($key)) {
            return (string) $key;
        }

        throw new InvalidArgumentException('A model key must be a string or integer.');
    }
}
