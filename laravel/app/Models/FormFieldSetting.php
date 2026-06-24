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

    public static function isRequired(string $formType, string $fieldKey)
    {
        return (bool) self::where('form_type', $formType)
                            ->where('field_key', $fieldKey)
                            ->value('is_required');
    }
}
