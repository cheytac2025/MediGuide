<?php

namespace App\Services\HospitalImport;

use App\Enums\HospitalDataSource;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Support\HospitalImport\QueenMaryClinicScheduleCatalog;
use Illuminate\Support\Facades\DB;

/**
 * Applies the reviewed Queen Mary catalog mapping to existing rows.
 *
 * Matching uses catalog names, not appearance. Rows that are not in the
 * reviewed import are left unchanged. Verified is never assigned.
 */
class HospitalRecordClassificationService
{
    public function __construct(
        private RecordProvenanceService $provenance,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(): array
    {
        return $this->run(execute: false);
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        return DB::transaction(function (): array {
            return $this->run(execute: true);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function run(bool $execute): array
    {
        $summary = [
            'dry_run' => ! $execute,
            'target' => HospitalDataSource::Imported->value,
            'verification' => 'pending_hospital_verification',
            'records' => [],
            'updated' => 0,
        ];

        $department = Department::query()
            ->where('name', QueenMaryClinicScheduleCatalog::PENDING_DEPARTMENT_NAME)
            ->first();

        $this->classify(
            $summary,
            $execute,
            'department',
            QueenMaryClinicScheduleCatalog::PENDING_DEPARTMENT_NAME,
            $department,
        );

        if ($department instanceof Department) {
            foreach ($this->catalogSpecializations() as $name) {
                $clinic = Clinic::query()
                    ->where('department_id', $department->id)
                    ->where('name', $name)
                    ->first();

                $this->classify($summary, $execute, 'clinic', $name, $clinic);
            }
        }

        foreach (QueenMaryClinicScheduleCatalog::doctors() as $record) {
            $name = (string) $record['display_name'];
            $doctor = Doctor::query()
                ->whereRaw('LOWER(display_name) = ?', [mb_strtolower($name)])
                ->first();

            $this->classify($summary, $execute, 'doctor', $name, $doctor);
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function classify(
        array &$summary,
        bool $execute,
        string $type,
        string $name,
        Department|Clinic|Doctor|null $record,
    ): void {
        if (! $record instanceof Department && ! $record instanceof Clinic && ! $record instanceof Doctor) {
            $summary['records'][] = [
                'type' => $type,
                'name' => $name,
                'action' => 'missing',
            ];

            return;
        }

        $current = $record->data_source instanceof HospitalDataSource
            ? $record->data_source
            : HospitalDataSource::Development;

        if ($current === HospitalDataSource::Imported) {
            $summary['records'][] = [
                'type' => $type,
                'name' => $name,
                'id' => $record->id,
                'action' => 'already_imported',
            ];

            return;
        }

        if ($current === HospitalDataSource::Verified) {
            $summary['records'][] = [
                'type' => $type,
                'name' => $name,
                'id' => $record->id,
                'action' => 'hold_verified',
            ];

            return;
        }

        $summary['records'][] = [
            'type' => $type,
            'name' => $name,
            'id' => $record->id,
            'from' => $current->value,
            'to' => HospitalDataSource::Imported->value,
            'action' => 'classify_imported',
        ];

        if (! $execute) {
            return;
        }

        $this->provenance->assign($record, HospitalDataSource::Imported);
        $summary['updated']++;
    }

    /**
     * @return list<string>
     */
    private function catalogSpecializations(): array
    {
        $names = [];

        foreach (QueenMaryClinicScheduleCatalog::doctors() as $record) {
            $label = trim((string) ($record['specialization'] ?? ''));

            if ($label !== '' && ! in_array($label, $names, true)) {
                $names[] = $label;
            }
        }

        return $names;
    }
}
