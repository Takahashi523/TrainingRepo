<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engineers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('name_kana', 100);
            $table->date('birth_date')->nullable();
            $table->string('nearest_station', 100)->nullable();
            $table->string('nearest_line', 100)->nullable();
            $table->date('available_from')->nullable();
            $table->boolean('has_negotiation_exp')->nullable();
            $table->unsignedSmallInteger('desired_rate')->nullable();
            $table->text('appeal_note')->nullable();
            $table->text('remarks')->nullable();
            $table->enum('status', ['proposable', 'interviewing', 'not_proposable'])->default('proposable');
            $table->text('ai_summary')->nullable();
            $table->dateTime('ai_summary_generated_at')->nullable();
            $table->foreignId('main_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('sub_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('proc_requirements')->nullable();
            $table->boolean('proc_basic_design')->nullable();
            $table->boolean('proc_detail_design')->nullable();
            $table->boolean('proc_development')->nullable();
            $table->boolean('proc_testing')->nullable();
            $table->boolean('proc_maintenance')->nullable();
            $table->boolean('work_style_onsite')->nullable();
            $table->boolean('work_style_hybrid')->nullable();
            $table->boolean('work_style_remote')->nullable();
            $table->timestamps();

            $table->index('status', 'idx_engineers_status');
            $table->index('available_from', 'idx_engineers_available_from');
            $table->index('main_user_id', 'idx_engineers_main_user_id');
            $table->index('sub_user_id', 'idx_engineers_sub_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engineers');
    }
};
