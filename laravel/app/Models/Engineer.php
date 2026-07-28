<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Engineer extends Model
{
    use HasFactory;

    public const PHASES = [
        ['key' => 'proc_requirements',  'name' => '要件定義'],
        ['key' => 'proc_basic_design',  'name' => '基本設計'],
        ['key' => 'proc_detail_design', 'name' => '詳細設計'],
        ['key' => 'proc_development',   'name' => '開発'],
        ['key' => 'proc_testing',       'name' => 'テスト'],
        ['key' => 'proc_maintenance',   'name' => '保守・運用'],
    ];

    public const WORK_STYLES = [
        ['key' => 'onsite', 'name' => '常駐'],
        ['key' => 'hybrid', 'name' => '一部リモート可'],
        ['key' => 'remote', 'name' => 'フルリモート'],
    ];

    protected $fillable = [
        'name', 'name_kana', 'birth_date', 'nearest_station', 'nearest_line',
        'available_from', 'has_negotiation_exp', 'desired_rate', 'appeal_note',
        'remarks', 'status', 'ai_summary', 'ai_summary_generated_at',
        'main_user_id', 'sub_user_id',
        'proc_requirements', 'proc_basic_design', 'proc_detail_design',
        'proc_development', 'proc_testing', 'proc_maintenance',
        'work_style_onsite', 'work_style_hybrid', 'work_style_remote',
    ];

    protected function casts(): array
    {
        return [
            'has_negotiation_exp'  => 'boolean',
            'proc_requirements'    => 'boolean',
            'proc_basic_design'    => 'boolean',
            'proc_detail_design'   => 'boolean',
            'proc_development'     => 'boolean',
            'proc_testing'         => 'boolean',
            'proc_maintenance'     => 'boolean',
            'work_style_onsite'    => 'boolean',
            'work_style_hybrid'    => 'boolean',
            'work_style_remote'    => 'boolean',
        ];
    }

    public function skills(): HasMany
    {
        return $this->hasMany(EngineerSkill::class);
    }

    public function mainUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'main_user_id');
    }

    public function subUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sub_user_id');
    }

    /**
     * 指定ユーザーがメインまたはサブで担当している人材に絞り込む。
     * ダッシュボードの「自分担当」集計軸（QA #70）で使用する共通スコープ。
     */
    public function scopeAssignedTo(Builder $query, int $userId): Builder
    {
        return $query->where(function (Builder $q) use ($userId) {
            $q->where('main_user_id', $userId)
                ->orWhere('sub_user_id', $userId);
        });
    }
}
