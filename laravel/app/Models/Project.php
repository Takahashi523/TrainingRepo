<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    /**
     * 案件 ENUM の表示ラベル（SSOT）。value => label。
     * フォーム選択肢（ProjectController）・マッチング結果の表示ラベル（MatchingResource）は
     * この定数のみを参照し、PHP/TS 双方でのラベル重複定義をなくす。
     */
    public const COMMERCIAL_FLOW_LABELS = [
        'prime' => 'プライム',
        'secondary' => '2次',
        'tertiary' => '3次',
        'other' => 'その他',
    ];

    public const WORK_STYLE_LABELS = [
        'onsite' => '常駐',
        'hybrid' => '一部リモート可',
        'remote' => 'フルリモート',
    ];

    public const STATUS_LABELS = [
        'open' => '募集中',
        'closed' => '終了',
        'pending' => 'ペンディング',
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
        'proc_requirements' => 'boolean',
        'proc_basic_design' => 'boolean',
        'proc_detail_design' => 'boolean',
        'proc_development' => 'boolean',
        'proc_testing' => 'boolean',
        'proc_maintenance' => 'boolean',
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
