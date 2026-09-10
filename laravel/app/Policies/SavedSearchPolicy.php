<?php

namespace App\Policies;

use App\Models\SavedSearch;
use App\Models\User;

class SavedSearchPolicy
{
    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, SavedSearch $savedSearch): bool
    {
        return $user->id === $savedSearch->user_id;
    }
}
