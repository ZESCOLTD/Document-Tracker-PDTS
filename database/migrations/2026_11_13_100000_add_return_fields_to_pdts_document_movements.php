<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdts_document_movements', function (Blueprint $table) {
            $table->string('return_category', 40)->nullable()->after('remarks');
        });
    }

    public function down(): void
    {
        Schema::table('pdts_document_movements', function (Blueprint $table) {
            $table->dropColumn('return_category');
        });
    }
};
