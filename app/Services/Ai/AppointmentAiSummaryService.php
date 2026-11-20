<?php

namespace App\Services\Ai;

use App\Models\Appointment;
use App\Models\AppointmentAiSummary;
use App\Support\AiBookingContext;
use App\Support\AiFrontDeskConversation;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Attaches an AI Front Desk concern summary after a successful booking.
 *
 * Generation uses session conversation state only. Booking never waits on
 * Anthropic. Duplicate inserts for the same appointment are ignored.
 */
class AppointmentAiSummaryService
{
    public function __construct(private AppointmentAiSummaryBuilder $builder) {}

    public function attachFromSessionIfEligible(
        Appointment $appointment,
        Session $session,
        int $clinicId,
        ?string $bookingConcern = null,
    ): ?AppointmentAiSummary {
        $context = AiBookingContext::consumeValidated($session);

        if ($context === null) {
            return null;
        }

        if (
            (int) $context['clinic_id'] !== $clinicId
            || (int) $context['doctor_id'] !== (int) $appointment->doctor_id
        ) {
            return null;
        }

        $state = AiFrontDeskConversation::state($session);
        $payload = $this->builder->fromConversation(
            $state,
            $context['specialization'],
            $bookingConcern,
        );

        if ($payload === null) {
            return null;
        }

        return $this->storeOnce($appointment, $payload);
    }

    /**
     * @param  array{
     *     concern_summary: string,
     *     reported_details: list<array{label: string, value: string}>,
     *     suggested_specialization: ?string,
     *     original_inputs: list<string>
     * }  $payload
     */
    public function storeOnce(Appointment $appointment, array $payload): AppointmentAiSummary
    {
        $existing = AppointmentAiSummary::query()
            ->where('appointment_id', $appointment->id)
            ->first();

        if ($existing instanceof AppointmentAiSummary) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($appointment, $payload): AppointmentAiSummary {
                $created = AppointmentAiSummary::query()->firstOrCreate(
                    ['appointment_id' => $appointment->id],
                    [
                        'concern_summary' => $payload['concern_summary'],
                        'reported_details' => json_encode(
                            $payload['reported_details'],
                            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                        ),
                        'suggested_specialization' => $payload['suggested_specialization'],
                        'original_inputs' => json_encode(
                            $payload['original_inputs'],
                            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                        ),
                        'source' => AppointmentAiSummary::SOURCE_AI_FRONT_DESK,
                    ],
                );

                return $created;
            });
        } catch (\Throwable $exception) {
            Log::warning('AI appointment summary could not be stored.', [
                'appointment_id' => $appointment->id,
                'exception_type' => $exception::class,
            ]);

            $existing = AppointmentAiSummary::query()
                ->where('appointment_id', $appointment->id)
                ->first();

            if ($existing instanceof AppointmentAiSummary) {
                return $existing;
            }

            throw $exception;
        }
    }
}
