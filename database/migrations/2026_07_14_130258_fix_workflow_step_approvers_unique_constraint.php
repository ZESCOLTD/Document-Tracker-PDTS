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
        Schema::table('workflow_step_approvers', function (Blueprint $table) {
            // MySQL uses the unique index to support the FK on workflow_step_id.
            // We must drop the FK first, swap the index, then re-add the FK.
            $table->dropForeign(['workflow_step_id']);

            // Drop the old constraint that was missing initiator_user_id.
            $table->dropUnique('wf_step_user_unique');

            // Add a plain index on workflow_step_id to support the FK
            $table->index('workflow_step_id', 'wf_step_id_index');

            // New constraint: unique per (step + user + initiator)
            $table->unique(
                ['workflow_step_id', 'user_id', 'initiator_user_id'],
                'wf_step_user_initiator_unique'
            );

            // Re-add the foreign key
            $table->foreign('workflow_step_id', 'workflow_step_approvers_workflow_step_id_foreign')
                ->references('id')
                ->on('workflow_steps')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_step_approvers', function (Blueprint $table) {
            $table->dropForeign(['workflow_step_id']);
            $table->dropUnique('wf_step_user_initiator_unique');
            $table->dropIndex('wf_step_id_index');
            $table->unique(['workflow_step_id', 'user_id'], 'wf_step_user_unique');
            $table->foreign('workflow_step_id', 'workflow_step_approvers_workflow_step_id_foreign')
                ->references('id')
                ->on('workflow_steps')
                ->cascadeOnDelete();
        });
    }
};
