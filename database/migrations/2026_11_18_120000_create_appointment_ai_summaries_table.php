<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One encrypted AI concern summary per appointment.
 *
 * Existing appointments stay untouched. Rows are created only when a patient
 * completes an AI Front Desk booking with a usable conversation state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_ai_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->unique()->constrained('appointments')->cascadeOnDelete();
            $table->longText('concern_summary');
            $table->longText('reported_details');
            $table->text('suggested_specialization')->nullable();
            $table->longText('original_inputs');
            $table->string('source', 40)->default('ai_front_desk');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_ai_summaries');
    }
};
