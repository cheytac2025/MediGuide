<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive staff scheduling tables.
 *
 * Apply later, after review, with `php artisan migrate`.
 * Do not rewrite older migrations and do not re-sign existing records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->text('body');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('from_date')->nullable();
            $table->string('from_start_time', 8)->nullable();
            $table->string('from_end_time', 8)->nullable();
            $table->date('to_date')->nullable();
            $table->string('to_start_time', 8)->nullable();
            $table->string('to_end_time', 8)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['appointment_id', 'kind']);
        });

        Schema::create('doctor_date_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->cascadeOnDelete();
            $table->date('override_date');
            $table->boolean('unavailable')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['doctor_id', 'override_date']);
        });

        Schema::create('doctor_date_override_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_date_override_id')->constrained()->cascadeOnDelete();
            $table->string('start_time', 8);
            $table->string('end_time', 8);
            $table->unsignedSmallInteger('slot_duration');
            $table->boolean('provisional_demonstration')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_date_override_windows');
        Schema::dropIfExists('doctor_date_overrides');
        Schema::dropIfExists('appointment_messages');
    }
};
