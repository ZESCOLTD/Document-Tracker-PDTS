<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdts_document_movements', function (Blueprint $table) {
            $table->foreignId('new_custodian_id')->nullable()
                ->after('acknowledged_by')
                ->constrained('users', indexName: 'pdts_mov_new_custodian_fk');
            $table->string('acknowledged_office', 191)->nullable()->after('new_custodian_id');
        });
    }

    public function down(): void
    {
        Schema::table('pdts_document_movements', function (Blueprint $table) {
            $table->dropForeign('pdts_mov_new_custodian_fk');
            $table->dropColumn(['new_custodian_id', 'acknowledged_office']);
        });
    }
};