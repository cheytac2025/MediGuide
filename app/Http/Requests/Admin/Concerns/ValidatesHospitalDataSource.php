<?php

namespace App\Http\Requests\Admin\Concerns;

use App\Enums\HospitalDataSource;
use Illuminate\Validation\Validator;

trait ValidatesHospitalDataSource
{
    /**
     * @return array<string, list<string>>
     */
    protected function prohibitedProvenanceRules(): array
    {
        return [
            'data_source' => ['prohibited'],
            'verified_at' => ['prohibited'],
            'verified_by_user_id' => ['prohibited'],
        ];
    }

    protected function hospitalProvidedSource(): HospitalDataSource
    {
        return HospitalDataSource::Imported;
    }

    protected function addIneligibleParentError(
        Validator $validator,
        ?HospitalDataSource $parent,
        string $field,
        string $message,
    ): void {
        if (! $parent instanceof HospitalDataSource) {
            return;
        }

        if ($this->hospitalProvidedSource()->isCompatibleWithParent($parent)) {
            return;
        }

        $validator->errors()->add($field, $message);
    }
}
