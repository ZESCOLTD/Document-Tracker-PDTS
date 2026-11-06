<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('document_items', function (Blueprint $table) {
            $table->id();
            $table->integer('document_id')->index();
            $table->string('number');
            $table->string('name',900);
            $table->string('description',2000)->nullable();

            $table->string('created_by_man_no', 191)->nullable();
            $table->string('created_by_name', 191)->nullable();
            $table->string('created_by_position', 191)->nullable();
            $table->string('created_by_email', 191)->nullable();
            $table->string('created_by_bu_code', 191)->nullable();
            $table->string('created_by_cc_code', 191)->nullable();

            $table->string('updated_by_man_no', 191)->nullable();
            $table->string('updated_by_name', 191)->nullable();
            $table->string('updated_by_position', 191)->nullable();
            $table->string('updated_by_email', 191)->nullable();
            $table->string('updated_by_bu_code', 191)->nullable();
            $table->string('updated_by_cc_code', 191)->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_items');
    }
};
