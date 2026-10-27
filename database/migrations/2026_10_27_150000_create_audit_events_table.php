<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit history. The chain state row serializes new events.
 * Application code does not update or delete audit_events. A database
 * administrator still can. That is detected by HMAC verification, and by an
 * off-database checkpoint when one has been stored independently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sequence')->unique();
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->string('actor_role', 32)->nullable();
            $table->string('action', 64)->index();
            $table->string('target_type', 32)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->char('occurred_at', 27);
            $table->string('outcome', 16);
            $table->char('correlation_id', 36)->nullable();
            $table->text('context');
            $table->unsignedSmallInteger('chain_version');
            $table->unsignedInteger('key_version');
            $table->char('previous_tag', 64);
            $table->char('tag', 64);
        });

        Schema::create('audit_chain_state', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->char('last_tag', 64)->nullable();
            $table->unsignedInteger('key_version');
            $table->timestamp('updated_at')->nullable();
        });

        DB::table('audit_chain_state')->insert([
            'id' => 1,
            'last_sequence' => 0,
            'last_tag' => null,
            'key_version' => 1,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_state');
        Schema::dropIfExists('audit_events');
    }
};
