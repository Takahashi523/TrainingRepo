<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_field_settings', function (Blueprint $table) {
            $table->id();
            $table->enum('form_type', ['engineer', 'project']);
            $table->string('field_key', 100);
            $table->boolean('is_required')->default(false);
            $table->boolean('is_system_required')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['form_type', 'field_key'], 'uk_form_field_settings');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_field_settings');
    }
};
