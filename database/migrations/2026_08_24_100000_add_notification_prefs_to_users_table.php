<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user notification delivery preferences.
 *
 *  notify_email       - master switch for outbound email
 *  notify_in_app      - master switch for the bell / database channel
 *  notify_categories  - JSON map of category => bool, e.g. {"documents":true,"handovers":true,"system":false}
 *
 * All notifications default to ON so existing behaviour is preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'notify_email')) {
                $table->boolean('notify_email')->default(true)->after('remember_token');
            }
            if (!Schema::hasColumn('users', 'notify_in_app')) {
                $table->boolean('notify_in_app')->default(true)->after('notify_email');
            }
            if (!Schema::hasColumn('users', 'notify_categories')) {
                $table->json('notify_categories')->nullable()->after('notify_in_app');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['notify_categories', 'notify_in_app', 'notify_email'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
