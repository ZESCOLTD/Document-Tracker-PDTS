<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-notification audit log.
 *
 * Every call to $user->notify(...) writes ONE row per channel (mail, database, ...)
 * via the NotificationSent / NotificationFailed events. Gives ops a single place
 * to answer: "did user X get the email for event Y?"
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email')->nullable()->index();
            $table->string('notification_class')->index();
            $table->string('channel', 32)->index();
            $table->string('status', 16)->default('sent')->index(); // sent | failed
            $table->string('subject')->nullable();
            $table->text('error')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['notification_class', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_log');
    }
};
