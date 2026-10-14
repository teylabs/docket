<?php

namespace Tey\Docket\Tests\Fixtures;

use Illuminate\Support\Facades\DB;

class PlainWork
{
    public function handle(): void
    {
        DB::table('work_results')->insert(['label' => 'plain']);
    }
}
