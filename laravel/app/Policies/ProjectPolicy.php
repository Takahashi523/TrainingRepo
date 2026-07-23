<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function delete(User $user, Project $project): bool
    {
        return $user->role === 'admin';
    }
}
