<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EngineerSkill extends Model
{
    protected $fillable = ['engineer_id', 'label', 'detail'];

    public function engineer(): BelongsTo
    {
        return $this->belongsTo(Engineer::class);
    }
}
