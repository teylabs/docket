<?php

namespace Tey\Docket;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/** @api */
trait HasRuns
{
    /** @return MorphMany<Run, $this> */
    public function runs(): MorphMany
    {
        return $this->morphMany(Run::class, 'subject');
    }

    /** @return MorphOne<Run, $this> */
    public function latestRun(): MorphOne
    {
        return $this->morphOne(Run::class, 'subject')->latestOfMany();
    }
}
