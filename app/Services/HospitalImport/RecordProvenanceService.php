<?php

namespace App\Services\HospitalImport;

use App\Enums\AuditAction;
use App\Enums\AuditOutcome;
use App\Enums\HospitalDataSource;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\User;
use App\Support\Audit\AuditTrailService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Assigns selectable provenance and explicit IT Administrator verification.
 *
 * Hospital Verified is never set from create/edit forms. Existing imported
 * rows are not promoted unless an administrator confirms them.
 */
class RecordProvenanceService
{
    public function assign(Model $record, HospitalDataSource $source): HospitalDataSource
    {
        $record = $this->hospitalRecord($record);
        $current = $this->currentSource($record);
        $source = HospitalDataSource::parse($source->value);

        HospitalDataSource::assertAssignable($current, $source);
        $this->assertCompatibleWithParent($this->parentSource($record), $source);

        if ($current === $source) {
            return $current;
        }

        $record->update(['data_source' => $source]);

        app(AuditTrailService::class)->recordFor(
            AuditAction::RecordProvenanceChanged,
            AuditOutcome::Success,
            $record,
            [
                'from' => $current->value,
                'to' => $source->value,
            ],
        );

        return $source;
    }

    public function applySelectable(Model $record, HospitalDataSource $source): HospitalDataSource
    {
        if ($source === HospitalDataSource::Verified) {
            throw ValidationException::withMessages([
                'data_source' => 'Hospital Verified can only be set with the verification action.',
            ]);
        }

        return $this->assign($record, $source);
    }

    public function verify(Model $record, User $actor): void
    {
        $record = $this->hospitalRecord($record);
        $current = $this->currentSource($record);

        if ($current === HospitalDataSource::Verified) {
            throw ValidationException::withMessages([
                'verification' => 'This record is already Hospital Verified.',
            ]);
        }

        if ($current !== HospitalDataSource::Imported) {
            throw ValidationException::withMessages([
                'verification' => 'Only Hospital Provided records can be marked Hospital Verified.',
            ]);
        }

        if (! $this->ancestorsAreVerified($record)) {
            throw ValidationException::withMessages([
                'verification' => $this->ancestorMessage($record),
            ]);
        }

        $record->forceFill([
            'data_source' => HospitalDataSource::Verified,
            'verified_at' => now(),
            'verified_by_user_id' => $actor->id,
        ])->save();

        app(AuditTrailService::class)->recordFor(
            AuditAction::RecordVerified,
            AuditOutcome::Success,
            $record,
            ['to' => HospitalDataSource::Verified->value],
            $actor,
        );
    }

    public function revoke(Model $record, User $actor, string $reason = 'revoked'): void
    {
        $record = $this->hospitalRecord($record);
        $current = $this->currentSource($record);

        if ($current !== HospitalDataSource::Verified) {
            throw ValidationException::withMessages([
                'verification' => 'This record is not Hospital Verified.',
            ]);
        }

        $record->forceFill([
            'data_source' => HospitalDataSource::Imported,
            'verified_at' => null,
            'verified_by_user_id' => null,
        ])->save();

        app(AuditTrailService::class)->recordFor(
            AuditAction::RecordVerificationRevoked,
            AuditOutcome::Success,
            $record,
            [
                'from' => HospitalDataSource::Verified->value,
                'to' => HospitalDataSource::Imported->value,
                'reason' => $reason,
            ],
            $actor,
        );
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  list<string>  $fields
     */
    public function revokeIfClinicalFieldsChanged(
        Model $record,
        array $before,
        array $after,
        array $fields,
        User $actor,
    ): void {
        $record = $this->hospitalRecord($record);

        if ($this->currentSource($record) !== HospitalDataSource::Verified) {
            return;
        }

        foreach ($fields as $field) {
            if ($this->normalized($before[$field] ?? null) !== $this->normalized($after[$field] ?? null)) {
                $this->revoke($record, $actor, 'details_changed');

                return;
            }
        }
    }

    public function assertCompatibleWithParent(?HospitalDataSource $parent, HospitalDataSource $child, string $field = 'data_source'): void
    {
        if (! $parent instanceof HospitalDataSource) {
            return;
        }

        if (! $child->isCompatibleWithParent($parent)) {
            throw ValidationException::withMessages([
                $field => 'This classification cannot be higher than the parent record.',
            ]);
        }
    }

    private function hospitalRecord(Model $record): Department|Clinic|Doctor
    {
        if (! $record instanceof Doctor && ! $record instanceof Clinic && ! $record instanceof Department) {
            throw new InvalidArgumentException('Provenance can only be assigned to hospital records.');
        }

        return $record;
    }

    private function currentSource(Department|Clinic|Doctor $record): HospitalDataSource
    {
        return $record->data_source instanceof HospitalDataSource
            ? $record->data_source
            : HospitalDataSource::Development;
    }

    private function parentSource(Department|Clinic|Doctor $record): ?HospitalDataSource
    {
        if ($record instanceof Clinic) {
            $record->loadMissing('department');

            return $record->department?->data_source instanceof HospitalDataSource
                ? $record->department->data_source
                : null;
        }

        if ($record instanceof Doctor) {
            $record->loadMissing('clinic');

            return $record->clinic?->data_source instanceof HospitalDataSource
                ? $record->clinic->data_source
                : null;
        }

        return null;
    }

    private function ancestorsAreVerified(Department|Clinic|Doctor $record): bool
    {
        if ($record instanceof Department) {
            return true;
        }

        if ($record instanceof Clinic) {
            $record->loadMissing('department');

            return $record->department?->data_source === HospitalDataSource::Verified;
        }

        $record->loadMissing('clinic.department');

        return $record->clinic?->data_source === HospitalDataSource::Verified
            && $record->clinic?->department?->data_source === HospitalDataSource::Verified;
    }

    private function ancestorMessage(Department|Clinic|Doctor $record): string
    {
        if ($record instanceof Clinic) {
            return 'The department must be Hospital Verified before this clinic can be verified.';
        }

        return 'The clinic and department must be Hospital Verified before this doctor can be verified.';
    }

    private function normalized(mixed $value): mixed
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        return $value;
    }
}
