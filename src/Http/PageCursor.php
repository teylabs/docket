<?php

namespace Tey\Docket\Http;

use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Validation\ValidationException;

/** @internal */
class PageCursor
{
    /** @param list<string> $parameters */
    public function resolve(Request $request, array $parameters): ?Cursor
    {
        $request->validate(['cursor' => ['nullable', 'string']]);
        $encoded = $request->string('cursor')->toString();
        if ($encoded === '') {
            return null;
        }

        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        $data = $decoded === false ? null : json_decode($decoded, true);
        if (! is_array($data) || ! is_bool($data['_pointsToNextItems'] ?? null)) {
            throw ValidationException::withMessages(['cursor' => 'The cursor is invalid.']);
        }

        $values = [];
        foreach ($parameters as $parameter) {
            $value = $data[$parameter] ?? null;
            if (! is_string($value) && ! is_int($value)) {
                throw ValidationException::withMessages(['cursor' => 'The cursor is invalid.']);
            }
            $values[$parameter] = $value;
        }

        return new Cursor($values, $data['_pointsToNextItems']);
    }
}
