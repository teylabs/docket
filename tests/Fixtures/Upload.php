<?php

namespace Tey\Docket\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Tey\Docket\HasRuns;

class Upload extends Model
{
    use HasRuns;

    protected $guarded = [];
}
