<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Project extends Model
{
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

    public const COMMERCIAL_FLOWS = [
        ['value' => 'prime',     'label' => 'プライム'],
        ['value' => 'secondary', 'label' => '2次'],
        ['value' => 'tertiary',  'label' => '3次'],
        ['value' => 'other',     'label' => 'その他'],
    ];

    public const STATUSES = [
        ['value' => 'open',    'label' => '募集中'],
        ['value' => 'closed',  'label' => '終了'],
        ['value' => 'pending', 'label' => 'ペンディング'],
    ];

    protected $fillable = [
        'name',
        'client_name',
        'headcount',
        'start_date',
        'rate_min',
        'rate_max',
        'rate_note',
        'commercial_flow',
        'work_style',
        'work_location_line',
        'work_location_station',
        'interview_count',
        'proc_requirements',
        'proc_basic_design',
        'proc_detail_design',
        'proc_development',
        'proc_testing',
        'proc_maintenance',
        'negotiation_required',
        'description',
        'work_env',
        'billing_range',
        'remarks',
        'status',
        'main_user_id',
        'sub_user_id',
    ];

    protected $casts = [
        'proc_requirements'    => 'boolean',
        'proc_basic_design'    => 'boolean',
        'proc_detail_design'   => 'boolean',
        'proc_development'     => 'boolean',
        'proc_testing'         => 'boolean',
        'proc_maintenance'     => 'boolean',
        'negotiation_required' => 'boolean',
    ];

    public function projectSkills(): HasMany
    {
        return $this->hasMany(ProjectSkill::class);
    }

    public function mainUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'main_user_id');
    }

    public function subUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sub_user_id');
    }
}
