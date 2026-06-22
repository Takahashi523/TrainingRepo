<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Project extends Model
{
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
