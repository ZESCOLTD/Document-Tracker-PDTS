<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workflow_definition_id')
                ->constrained('workflow_definitions')
                ->cascadeOnDelete();

            $table->unsignedInteger('step_order'); // 1,2,3,4
            $table->string('step_name'); // Line Manager, Director, CEO
            $table->string('step_code')->nullable(); // line_manager, director, ceo

            // Who approves this step?
            $table->string('approver_type')->default('user');
            // user, role, job_title, department_head, reporting_manager

            $table->string('approver_value')->nullable();
            // e.g. user_id, role name, job title name, etc

            $table->boolean('allow_revert')->default(true);
            $table->boolean('allow_reject')->default(true);
            $table->boolean('allow_approve')->default(true);
            $table->boolean('comment_required')->default(true);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['workflow_definition_id', 'step_order']);
            $table->index(['workflow_definition_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_steps');
    }
};
