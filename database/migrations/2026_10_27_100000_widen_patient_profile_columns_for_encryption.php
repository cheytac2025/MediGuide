<?php

use App\Support\PatientDataEncryption;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widens patient profile columns so they can hold AES-256 ciphertext.
 *
 * Forward-only in practice: existing DATE and VARCHAR values convert to TEXT
 * without loss. Rolling back is refused while any value is still encrypted,
 * because narrowing the columns would truncate ciphertext. Run
 * `php artisan patient-data:encrypt --reverse --execute` first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->text('date_of_birth')->change();
            $table->text('contact_number')->change();
        });
    }

    public function down(): void
    {
        $isPlain = fn (?string $value): bool => in_array(
            PatientDataEncryption::classify($value),
            [PatientDataEncryption::PLAINTEXT, PatientDataEncryption::EMPTY],
            true,
        );

        $encrypted = DB::table('patients')
            ->select(['id', 'date_of_birth', 'contact_number'])
            ->lazyById()
            ->contains(fn (object $row): bool => ! $isPlain($row->date_of_birth) || ! $isPlain($row->contact_number));

        if ($encrypted) {
            throw new RuntimeException('Patient profile columns still contain encrypted or unreadable values. Decrypt them before rolling back.');
        }

        Schema::table('patients', function (Blueprint $table) {
            $table->date('date_of_birth')->change();
            $table->string('contact_number', 20)->change();
        });
    }
};
