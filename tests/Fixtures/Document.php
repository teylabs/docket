<?php

namespace Tey\Docket\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Tey\Docket\HasRuns;

class Document extends Model
{
    use HasRuns, HasUlids;

    protected $guarded = [];
}
