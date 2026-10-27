<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers the appointment slot that a seal covered.
 *
 * An HMAC cannot be reversed. These columns let a tampered date, time, or
 * doctor id keep the original slot occupied. They are not clinical notes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('record_integrity', function (Blueprint $table) {
            $table->unsignedBigInteger('slot_patient_id')->nullable()->after('hmac');
            $table->unsignedBigInteger('slot_doctor_id')->nullable()->after('slot_patient_id');
            $table->date('slot_date')->nullable()->after('slot_doctor_id');
            $table->char('slot_start', 8)->nullable()->after('slot_date');
            $table->char('slot_end', 8)->nullable()->after('slot_start');
            $table->index(['slot_doctor_id', 'slot_date']);
            $table->index(['slot_patient_id', 'slot_date']);
        });
    }

    public function down(): void
    {
        Schema::table('record_integrity', function (Blueprint $table) {
            $table->dropIndex(['slot_doctor_id', 'slot_date']);
            $table->dropIndex(['slot_patient_id', 'slot_date']);
            $table->dropColumn([
                'slot_patient_id',
                'slot_doctor_id',
                'slot_date',
                'slot_start',
                'slot_end',
            ]);
        });
    }
};
