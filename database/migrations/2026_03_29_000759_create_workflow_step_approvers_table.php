<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_step_approvers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workflow_definition_id')
                ->constrained('workflow_definitions')
                ->cascadeOnDelete();

            $table->foreignId('workflow_step_id')
                ->constrained('workflow_steps')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->integer('initiator_user_id');

            $table->boolean('is_primary')->default(true);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['workflow_step_id', 'user_id'], 'wf_step_user_unique');
            $table->index(['workflow_definition_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_step_approvers');
    }
};
