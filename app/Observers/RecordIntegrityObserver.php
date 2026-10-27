<?php

namespace App\Observers;

use App\Support\Integrity\IntegrityStatus;
use App\Support\Integrity\RecordIntegrityService;
use Illuminate\Database\Eloquent\Model;

/**
 * Seals trusted creates and refreshes a seal after a trusted update.
 *
 * The model opens the transaction before the row changes. This observer
 * writes the seal before that transaction commits. An unsigned legacy row
 * stays unsigned until enrollment. An invalid seal blocks the write.
 */
class RecordIntegrityObserver
{
    /** @var array<int, bool> */
    private array $refresh = [];

    public function created(Model $model): void
    {
        $this->integrity()->writeSeal($this->integrity()->typeOf($model), (int) $model->getKey());
    }

    public function updating(Model $model): void
    {
        $status = $this->integrity()->guardForUpdate($model);
        $this->refresh[spl_object_id($model)] = $status === IntegrityStatus::Valid;
    }

    public function updated(Model $model): void
    {
        $key = spl_object_id($model);
        $refresh = $this->refresh[$key] ?? false;
        unset($this->refresh[$key]);

        if ($refresh) {
            $this->integrity()->writeSeal($this->integrity()->typeOf($model), (int) $model->getKey());
        }
    }

    private function integrity(): RecordIntegrityService
    {
        return app(RecordIntegrityService::class);
    }
}
