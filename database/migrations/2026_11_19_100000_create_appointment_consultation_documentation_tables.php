<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doctor-authored consultation notes and patient-facing instructions.
 *
 * Clinical text lives outside the appointment HMAC seal (same pattern as
 * appointment messages and AI summaries). Rows cascade with the appointment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_doctor_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->unique();
            $table->foreignId('author_user_id');
            $table->longText('internal_notes');
            $table->timestamps();

            $table->foreign('appointment_id', 'appt_doc_notes_appt_fk')
                ->references('id')->on('appointments')->cascadeOnDelete();
            $table->foreign('author_user_id', 'appt_doc_notes_author_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('appointment_patient_instructions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->unique();
            $table->foreignId('author_user_id');
            $table->longText('draft_prescription')->nullable();
            $table->longText('draft_reminders')->nullable();
            $table->longText('draft_follow_up')->nullable();
            $table->longText('published_prescription')->nullable();
            $table->longText('published_reminders')->nullable();
            $table->longText('published_follow_up')->nullable();
            $table->unsignedInteger('published_revision')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('appointment_id', 'appt_pat_instr_appt_fk')
                ->references('id')->on('appointments')->cascadeOnDelete();
            $table->foreign('author_user_id', 'appt_pat_instr_author_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('published_by_user_id', 'appt_pat_instr_publisher_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('appointment_patient_instruction_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id');
            $table->foreignId('instruction_id');
            $table->unsignedInteger('revision_number');
            $table->longText('prescription')->nullable();
            $table->longText('reminders')->nullable();
            $table->longText('follow_up')->nullable();
            $table->string('revision_reason', 255)->nullable();
            $table->foreignId('published_by_user_id');
            $table->timestamp('published_at');
            $table->timestamps();

            $table->unique(['appointment_id', 'revision_number'], 'appt_instr_rev_unique');
            $table->index(['instruction_id', 'revision_number'], 'appt_instr_rev_lookup_idx');

            $table->foreign('appointment_id', 'appt_instr_rev_appt_fk')
                ->references('id')->on('appointments')->cascadeOnDelete();
            $table->foreign('instruction_id', 'appt_instr_rev_instr_fk')
                ->references('id')->on('appointment_patient_instructions')->cascadeOnDelete();
            $table->foreign('published_by_user_id', 'appt_instr_rev_publisher_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_patient_instruction_revisions');
        Schema::dropIfExists('appointment_patient_instructions');
        Schema::dropIfExists('appointment_doctor_notes');
    }
};
