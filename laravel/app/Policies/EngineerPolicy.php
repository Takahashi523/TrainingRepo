<?php

namespace App\Policies;

use App\Models\Engineer;
use App\Models\User;

class EngineerPolicy
{
    public function delete(User $user, Engineer $engineer): bool
    {
        return $user->role === 'admin';
    }
}
