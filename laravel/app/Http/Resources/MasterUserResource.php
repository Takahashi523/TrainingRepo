<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * マスタ管理のユーザー一覧用 Resource。
 * 既存の UserResource（id/name のみ・担当営業表示用）とは責務が異なるため新設。
 *
 * @mixin User
 */
class MasterUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'role_label' => $this->role === 'admin' ? '管理者' : '一般',
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'version' => $this->version,
        ];
    }
}
