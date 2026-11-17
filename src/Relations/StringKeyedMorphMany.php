<?php

namespace Tey\Docket\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A morphMany whose foreign key is a string column (docket_runs.subject_id): the parent's key is compared and eager-loaded as a string, so an
 * integer-keyed model works on PostgreSQL behind a pooler (emulated prepares inline an integer literal, and `varchar = integer` is refused).
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends MorphMany<TRelatedModel, TDeclaringModel>
 */
class StringKeyedMorphMany extends MorphMany
{
    use StringKeys;
}
