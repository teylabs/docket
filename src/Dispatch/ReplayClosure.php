<?php

namespace Tey\Docket\Dispatch;

use Laravel\SerializableClosure\Serializers\Native;

/** @internal */
class ReplayClosure extends Native
{
    protected function mapByReference(&$data): void
    {
        if (is_resource($data)) {
            throw new \LogicException('A live resource cannot be replayed.');
        }

        if (is_array($data)) {
            $copy = [];
            foreach ($data as $key => $value) {
                $copy[$key] = $value;
            }
            $data = $copy;
        }

        parent::mapByReference($data);
    }
}
