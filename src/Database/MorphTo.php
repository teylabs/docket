<?php

namespace Tey\Docket\Database;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo as EloquentMorphTo;
use InvalidArgumentException;

/**
 * Keep application models on their own connection, not Docket's connection.
 *
 * @internal
 *
 * @template TDeclaringModel of Model
 *
 * @extends EloquentMorphTo<Model, TDeclaringModel>
 */
class MorphTo extends EloquentMorphTo
{
    public function createModelByType($type)
    {
        $class = Model::getActualClassNameForMorph($type);

        if (! is_a($class, Model::class, true)) {
            throw new InvalidArgumentException('A run subject or initiator must be an Eloquent model.');
        }

        return new $class;
    }
}
