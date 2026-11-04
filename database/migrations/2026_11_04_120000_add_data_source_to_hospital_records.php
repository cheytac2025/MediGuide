<?php

use App\Enums\HospitalDataSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive record-level provenance. Existing rows default to development.
     * Imported poster records are classified separately; this migration does
     * not promote any row to imported or verified.
     */
    public function up(): void
    {
        $default = HospitalDataSource::Development->value;

        Schema::table('departments', function (Blueprint $table) use ($default): void {
            $table->string('data_source')->default($default)->after('status');
        });

        Schema::table('clinics', function (Blueprint $table) use ($default): void {
            $table->string('data_source')->default($default)->after('status');
        });

        Schema::table('doctors', function (Blueprint $table) use ($default): void {
            $table->string('data_source')->default($default)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->dropColumn('data_source');
        });

        Schema::table('clinics', function (Blueprint $table): void {
            $table->dropColumn('data_source');
        });

        Schema::table('doctors', function (Blueprint $table): void {
            $table->dropColumn('data_source');
        });
    }
};
