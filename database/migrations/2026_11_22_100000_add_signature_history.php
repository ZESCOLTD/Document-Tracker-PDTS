<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Signature security, stage 2:
 *  - each person signs with either a PIN or a signature password;
 *  - every signature image ever uploaded is kept (never deleted), with its
 *    fingerprint, so each signed document can be traced to the exact image;
 *  - every change to a signature or its PIN/password is recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('signature_secret_type', 10)->nullable()->after('signature_pin');
        });

        Schema::create('user_signature_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('storage_path');
            $table->string('sha256', 64)->index();
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->timestamp('uploaded_at');
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
        });

        Schema::create('user_signature_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->json('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['user_id', 'occurred_at']);
        });

        // Signatures set up before this change.
        DB::table('users')->whereNotNull('signature_pin')->update(['signature_secret_type' => 'pin']);

        foreach (DB::table('users')->whereNotNull('signature_path')->get(['id', 'signature_path', 'signature_updated_at']) as $user) {
            $bytes = Storage::disk('private')->exists($user->signature_path) ? Storage::disk('private')->get($user->signature_path) : null;
            if ($bytes === null) {
                continue;
            }
            $size = @getimagesizefromstring($bytes) ?: [0, 0];
            DB::table('user_signature_images')->insert([
                'user_id' => $user->id,
                'storage_path' => $user->signature_path,
                'sha256' => hash('sha256', $bytes),
                'width' => $size[0],
                'height' => $size[1],
                'uploaded_at' => $user->signature_updated_at ?? now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_signature_events');
        Schema::dropIfExists('user_signature_images');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('signature_secret_type');
        });
    }
};
