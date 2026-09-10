<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engineer_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('engineer_id')->constrained('engineers')->cascadeOnDelete();
            $table->string('label', 15)->nullable();
            $table->string('detail', 500)->nullable();
            $table->timestamps();

            $table->index('engineer_id', 'idx_engineer_skills_engineer_id');
            $table->index('label', 'idx_engineer_skills_label');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engineer_skills');
    }
};
