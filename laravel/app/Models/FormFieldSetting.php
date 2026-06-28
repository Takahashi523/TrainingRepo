<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormFieldSetting extends Model
{
    protected $fillable = ['form_type', 'field_key', 'is_required', 'is_system_required', 'updated_by'];

    protected function casts(): array
    {
        return [
            'is_required'        => 'boolean',
            'is_system_required' => 'boolean',
        ];
    }
}
