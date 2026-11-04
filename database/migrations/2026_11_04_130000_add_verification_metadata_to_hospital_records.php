<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive IT Administrator verification metadata. Existing imported
     * rows stay unverified. This does not promote any record to verified.
     */
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->timestamp('verified_at')->nullable()->after('data_source');
            $table->foreignId('verified_by_user_id')->nullable()->after('verified_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('clinics', function (Blueprint $table): void {
            $table->timestamp('verified_at')->nullable()->after('data_source');
            $table->foreignId('verified_by_user_id')->nullable()->after('verified_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('doctors', function (Blueprint $table): void {
            $table->timestamp('verified_at')->nullable()->after('data_source');
            $table->foreignId('verified_by_user_id')->nullable()->after('verified_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('verified_by_user_id');
            $table->dropColumn('verified_at');
        });

        Schema::table('clinics', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('verified_by_user_id');
            $table->dropColumn('verified_at');
        });

        Schema::table('doctors', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('verified_by_user_id');
            $table->dropColumn('verified_at');
        });
    }
};
