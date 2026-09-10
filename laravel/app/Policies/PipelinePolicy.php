<?php

namespace App\Policies;

use App\Models\Pipeline;
use App\Models\User;

class PipelinePolicy
{
    /**
     * パイプラインの物理削除は管理者のみ許可（QA #71・EngineerPolicy と同一方針）。
     */
    public function delete(User $user, Pipeline $pipeline): bool
    {
        return $user->role === 'admin';
    }
}
