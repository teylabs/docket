<?php

namespace Tey\Docket\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Tey\Docket\Run;

class ReadOnlyRunPolicy
{
    public function view(?Authenticatable $user, Run $run): bool
    {
        return $run->name !== 'hidden';
    }

    public function cancel(?Authenticatable $user, Run $run): bool
    {
        return false;
    }

    public function retry(?Authenticatable $user, Run $run): bool
    {
        return false;
    }
}
