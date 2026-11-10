<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_instances', function (Blueprint $table) {
            $table->unsignedInteger('approval_round')->default(1);
            $table->json('workflow_snapshot')->nullable();
            $table->json('current_approver_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_instances', function (Blueprint $table) {
            $table->dropColumn(['approval_round', 'workflow_snapshot', 'current_approver_ids']);
        });
    }
};