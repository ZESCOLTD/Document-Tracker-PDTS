<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('number_ref');
            $table->string('number')->nullable();
            $table->integer('version')->nullable();
            $table->string('name',900);
            $table->string('description',2000)->nullable();
            $table->integer('document_classification_id')->nullable();
            $table->integer('document_type_id')->nullable();

            $table->boolean('is_publish')->default(0)->nullable();
            $table->boolean('should_approve')->default(0)->nullable();
            $table->boolean('is_migrated')->default(0)->nullable();
            $table->boolean('is_visible_to_all')->default(0)->nullable();

            $table->dateTime('publish_at')->nullable();
            $table->dateTime('last_review_at')->nullable();
            $table->dateTime('due_review_at')->nullable();

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

            $table->string('approved_by_man_no', 191)->nullable();
            $table->string('approved_by_name', 191)->nullable();
            $table->string('approved_by_position', 191)->nullable();
            $table->string('approval_comment', 600)->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
