<?php

namespace App\Models\Concerns;

use App\Support\Integrity\IntegrityStatus;
use App\Support\Integrity\RecordIntegrityService;

trait ChecksRecordIntegrity
{
    public function integrityStatus(): IntegrityStatus
    {
        return app(RecordIntegrityService::class)->statusOf($this);
    }

    /**
     * Whether protected values may be shown.
     *
     * Unsigned legacy rows remain readable until strict mode is enabled.
     * Strict mode accepts only a matching seal. Invalid seals are never shown.
     */
    public function integrityIsValid(): bool
    {
        $status = $this->integrityStatus();

        if ($status === IntegrityStatus::Valid) {
            return true;
        }

        return $status === IntegrityStatus::Unsigned && ! config('integrity.strict');
    }
}
