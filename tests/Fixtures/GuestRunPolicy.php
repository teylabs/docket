<?php

namespace Tey\Docket\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Tey\Docket\Run;

/** Admits guests (a nullable user) to every run: the shape a public shared run needs. */
class GuestRunPolicy
{
    public function view(?Authenticatable $user, Run $run): bool
    {
        return true;
    }
}
