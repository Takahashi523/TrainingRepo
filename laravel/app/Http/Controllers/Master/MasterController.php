<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Resources\FormSettingResource;
use App\Http\Resources\MasterUserResource;
use App\Models\FormFieldSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * マスタ管理トップ（GET /master）。
 * ユーザー一覧とフォーム設定（engineer / project 両方）を 1 レスポンスでまとめて返す。
 */
class MasterController extends Controller
{
    public function index(): Response
    {
        $users = User::query()
            ->select(['id', 'name', 'email', 'role', 'last_login_at'])
            ->orderBy('name')
            ->orderBy('id') // 同名時のタイブレーク（順序を決定的にする）
            ->get();

        $settings = FormFieldSetting::query()
            ->select(['form_type', 'field_key', 'is_required', 'is_system_required'])
            ->get()
            ->groupBy('form_type');

        // ->resolve() で各リソースを解決済みの配列として渡す（data ラッパなし）。
        // ※ ->collection は Inertia のシリアライズ経路で JsonResource::toArray(Request) が
        //   引数なしに呼ばれ空になるため使用しない。
        return Inertia::render('Master/Index', [
            'users' => MasterUserResource::collection($users)->resolve(),
            'form_settings' => [
                'engineer' => FormSettingResource::collection($this->orderedSettings($settings->get('engineer')))->resolve(),
                'project' => FormSettingResource::collection($this->orderedSettings($settings->get('project')))->resolve(),
            ],
        ]);
    }

    /**
     * FIELD_LABELS の定義順で並べ替える（表示順は id ではなく SSOT に従う）。
     */
    private function orderedSettings(?Collection $settings): Collection
    {
        return ($settings ?? collect())
            ->sortBy(fn (FormFieldSetting $s) => $s->displayOrder())
            ->values();
    }
}
