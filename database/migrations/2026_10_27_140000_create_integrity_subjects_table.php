<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records that a row was brought under integrity protection.
 *
 * Deleting the HMAC in record_integrity must not make that row look like a
 * legacy record that was never enrolled. Slot columns repeat the last trusted
 * appointment slot so a deleted seal cannot free it. They are not clinical notes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrity_subjects', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('slot_patient_id')->nullable();
            $table->unsignedBigInteger('slot_doctor_id')->nullable();
            $table->date('slot_date')->nullable();
            $table->char('slot_start', 8)->nullable();
            $table->char('slot_end', 8)->nullable();
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id']);
            $table->index(['slot_doctor_id', 'slot_date']);
            $table->index(['slot_patient_id', 'slot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrity_subjects');
    }
};
