<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdts_offices', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->nullable()->unique();
            $table->string('name', 191);
            $table->foreignId('department_id')->constrained('pdts_departments')->restrictOnDelete();
            $table->foreignId('parent_office_id')->nullable()
                ->constrained('pdts_offices')->restrictOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['department_id', 'name'], 'pdts_offices_department_name_unique');
            $table->index(['department_id', 'is_active'], 'pdts_offices_department_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdts_offices');
    }
};