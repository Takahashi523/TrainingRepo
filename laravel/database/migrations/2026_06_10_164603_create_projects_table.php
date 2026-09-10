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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('client_name', 100)->nullable();
            $table->unsignedTinyInteger('headcount')->nullable();
            $table->date('start_date')->nullable();
            $table->unsignedSmallInteger('rate_min')->nullable();
            $table->unsignedSmallInteger('rate_max')->nullable();
            $table->string('rate_note', 100)->nullable();
            $table->enum('commercial_flow', ['prime', 'secondary', 'tertiary', 'other'])->nullable();
            $table->enum('work_style', ['remote', 'hybrid', 'onsite'])->nullable();
            $table->string('work_location_line', 100)->nullable();
            $table->string('work_location_station', 100)->nullable();
            $table->unsignedTinyInteger('interview_count')->nullable();
            $table->boolean('proc_requirements')->nullable();
            $table->boolean('proc_basic_design')->nullable();
            $table->boolean('proc_detail_design')->nullable();
            $table->boolean('proc_development')->nullable();
            $table->boolean('proc_testing')->nullable();
            $table->boolean('proc_maintenance')->nullable();
            $table->boolean('negotiation_required')->nullable();
            $table->text('description')->nullable();
            $table->text('work_env')->nullable();
            $table->string('billing_range', 100)->nullable();
            $table->text('remarks')->nullable();
            $table->enum('status', ['open', 'closed', 'pending'])->default('open');
            $table->foreignId('main_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('sub_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status', 'idx_projects_status');
            $table->index('start_date', 'idx_projects_start_date');
            $table->index('main_user_id', 'idx_projects_main_user_id');
            $table->index('sub_user_id', 'idx_projects_sub_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
