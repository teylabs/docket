<?php

namespace Tey\Docket\Tests\Fixtures;

class WorkspacePolicy
{
    public function view(User $user, Workspace $workspace): bool
    {
        return $workspace->members()->whereKey($user->getAuthIdentifier())->exists();
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $workspace->members()->whereKey($user->getAuthIdentifier())->wherePivot('can_manage', true)->exists();
    }
}
