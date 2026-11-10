<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdts_user_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('principal_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('delegate_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamps();

            $table->index(['principal_user_id', 'delegate_user_id', 'revoked_at'], 'pdts_delegation_principal_delegate_idx');
            $table->index(['delegate_user_id', 'revoked_at'], 'pdts_delegation_delegate_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdts_user_delegations');
    }
};