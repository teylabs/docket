<?php

namespace Tey\Docket\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * A morphOne over a string foreign key column, as StringKeyedMorphMany.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends MorphOne<TRelatedModel, TDeclaringModel>
 */
class StringKeyedMorphOne extends MorphOne
{
    use StringKeys;
}
