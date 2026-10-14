<?php

namespace Tey\Docket\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Tey\Docket\Run;

/** @api */
class RunPolicy
{
    public function view(Authenticatable $user, Run $run): bool
    {
        $id = $user->getAuthIdentifier();

        return $user instanceof Model
            && (is_string($id) || is_int($id))
            && $run->initiator_type === $user->getMorphClass()
            && $run->initiator_id !== null
            && $run->initiator_id === (string) $id;
    }

    public function cancel(Authenticatable $user, Run $run): bool
    {
        return $this->view($user, $run);
    }

    public function retry(Authenticatable $user, Run $run): bool
    {
        return $this->view($user, $run);
    }
}
