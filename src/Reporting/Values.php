<?php

namespace Tey\Docket\Reporting;

use InvalidArgumentException;

/** @internal */
class Values
{
    public static function code(string $code): void
    {
        if (! preg_match('/^[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*$/D', $code) || strlen($code) > 255 || str_starts_with($code, 'run.')) {
            throw new InvalidArgumentException('Use a short machine code outside the reserved run. prefix.');
        }
    }

    /**
     * @param  array<array-key, mixed>  $params
     * @return array<string, scalar|null>
     */
    public static function params(array $params): array
    {
        if (count($params) > 20) {
            throw new InvalidArgumentException('Params may contain at most 20 scalar entries.');
        }

        $validated = [];
        foreach ($params as $key => $value) {
            if (! is_string($key) || (! is_scalar($value) && $value !== null)) {
                throw new InvalidArgumentException('Params must have string keys and scalar or null values.');
            }
            $validated[$key] = $value;
        }

        self::json($params, 4096);

        return $validated;
    }

    public static function checkpoint(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $entry) {
                if (! is_scalar($entry) && $entry !== null) {
                    throw new InvalidArgumentException('A checkpoint must be a scalar or a flat array of scalars.');
                }
            }
        } elseif (! is_scalar($value) && $value !== null) {
            throw new InvalidArgumentException('A checkpoint must be a scalar or a flat array of scalars.');
        }

        self::json($value, 4096);
    }

    public static function key(mixed $key): string
    {
        if ((! is_string($key) && ! is_int($key)) || (string) $key === '' || strlen((string) $key) > 255) {
            throw new InvalidArgumentException('An item key must be a non-empty string or integer of at most 255 bytes.');
        }

        return (string) $key;
    }

    private static function json(mixed $value, int $limit): void
    {
        $json = json_encode($value);

        if ($json === false || strlen($json) > $limit) {
            throw new InvalidArgumentException('Reporting values must be valid JSON of at most '.$limit.' bytes.');
        }
    }
}
