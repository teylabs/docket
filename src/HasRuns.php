<?php

namespace Tey\Docket;

use Tey\Docket\Relations\StringKeyedMorphMany;
use Tey\Docket\Relations\StringKeyedMorphOne;

/** @api */
trait HasRuns
{
    /**
     * This model's runs. subject_id is a string column, so the key is compared as one (an integer key works on PostgreSQL behind a pooler).
     *
     * @return StringKeyedMorphMany<Run, $this>
     */
    public function runs(): StringKeyedMorphMany
    {
        $run = $this->newRelatedInstance(Run::class);
        $table = $run->getTable();

        return new StringKeyedMorphMany($run->newQuery(), $this, $table.'.subject_type', $table.'.subject_id', $this->getKeyName());
    }

    /** @return StringKeyedMorphOne<Run, $this> */
    public function latestRun(): StringKeyedMorphOne
    {
        $run = $this->newRelatedInstance(Run::class);
        $table = $run->getTable();

        /** @var StringKeyedMorphOne<Run, $this> */
        return (new StringKeyedMorphOne($run->newQuery(), $this, $table.'.subject_type', $table.'.subject_id', $this->getKeyName()))->latestOfMany();
    }
}
