<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('man_no')->nullable()->unique();
            $table->string('nrc')->nullable()->unique();
            $table->string('username')->nullable();
            $table->string('name')->nullable();
            $table->date('dob')->nullable();
            $table->string('sex', 10)->nullable();
            $table->string('mobile_no')->nullable();
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();

            $table->string('bu_code')->nullable();
            $table->string('bu_name')->nullable();
            $table->string('cc_code')->nullable();
            $table->string('cc_name')->nullable();

            $table->string('directorate')->nullable();
            $table->string('division')->nullable();
            $table->string('location_code')->nullable();
            $table->string('location')->nullable();
            $table->string('function_section_code')->nullable();
            $table->string('functional_section')->nullable();
            $table->string('station')->nullable();
            $table->string('position')->nullable();
            $table->string('job_code')->nullable();
            $table->string('job_title')->nullable();
            $table->string('grade')->nullable();

            $table->string('group_type')->nullable();
            $table->string('con_st_code')->nullable();
            $table->string('contract_type')->nullable();
            $table->date('con_wef_date')->nullable();
            $table->date('con_wet_date')->nullable();

            $table->boolean('is_banned')->default(false);
            $table->timestamp('banned_until')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
