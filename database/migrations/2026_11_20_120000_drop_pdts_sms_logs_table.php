<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The scan page no longer sends the document history by SMS, so its log
 * table is removed. dropIfExists keeps this safe on databases that never had it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('pdts_sms_logs');
    }

    public function down(): void
    {
        // Not restored: the SMS feature was removed.
    }
};
