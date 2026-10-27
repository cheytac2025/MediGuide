<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores HMAC-SHA-256 seals. The sealed field values stay on their own tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_integrity', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->unsignedTinyInteger('integrity_version');
            $table->unsignedSmallInteger('key_version');
            $table->char('hmac', 64);
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_integrity');
    }
};
