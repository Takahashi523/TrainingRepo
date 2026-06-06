<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Engineer extends Model
{
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
}
