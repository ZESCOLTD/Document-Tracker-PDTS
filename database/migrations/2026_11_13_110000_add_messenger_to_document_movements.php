<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdts_document_movements', function (Blueprint $table) {
            $table->foreignId('messenger_id')->nullable()->constrained('users');
            $table->timestamp('picked_up_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pdts_document_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('messenger_id');
            $table->dropColumn('picked_up_at');
        });
    }
};
