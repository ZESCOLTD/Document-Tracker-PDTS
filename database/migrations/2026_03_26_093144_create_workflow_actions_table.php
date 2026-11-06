<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_actions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workflow_instance_id')->constrained()->cascadeOnDelete();

            // optional but useful for reporting speed
            $table->morphs('workflowable');

            $table->foreignId('workflow_step_id')->nullable()->constrained('workflow_steps')->nullOnDelete();

            $table->unsignedInteger('step_order')->nullable();
            $table->string('step_name')->nullable();

            $table->string('action');
            // approved, rejected, reverted, submitted

            $table->text('comment')->nullable();

            $table->foreignId('acted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acted_at')->nullable();

            $table->timestamps();

            $table->index(['action']);
            $table->index(['workflow_instance_id']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_actions');
    }
};
