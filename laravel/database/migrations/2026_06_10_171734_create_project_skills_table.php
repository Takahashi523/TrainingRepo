<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('project_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->enum('skill_type', ['required', 'preferred']);
            $table->string('label', 15)->nullable();
            $table->string('detail', 500)->nullable();
            $table->timestamps();

            $table->index('project_id', 'idx_project_skills_project_id');
            $table->index('skill_type', 'idx_project_skills_skill_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_skills');
    }
};
