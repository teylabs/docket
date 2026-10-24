<?php

namespace Tey\Docket\Policies;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Tey\Docket\Run;

/** @api */
class SubjectRunPolicy
{
    /** @param (Closure(Model): ?Model)|null $resolveSubject */
    public function __construct(private readonly ?Closure $resolveSubject = null) {}

    public function view(Authenticatable $user, Run $run): bool
    {
        return $this->allows($user, $run, 'view');
    }

    public function cancel(Authenticatable $user, Run $run): bool
    {
        return $this->allows($user, $run, 'update');
    }

    public function retry(Authenticatable $user, Run $run): bool
    {
        return $this->allows($user, $run, 'update');
    }

    private function allows(Authenticatable $user, Run $run, string $ability): bool
    {
        // Re-read the subject even when a ticket reuses its loaded run.
        $subject = $run->subject()->getResults();

        if ($subject !== null && $this->resolveSubject !== null) {
            $subject = ($this->resolveSubject)($subject);
        }

        return $subject !== null && Gate::forUser($user)->allows($ability, $subject);
    }
}
