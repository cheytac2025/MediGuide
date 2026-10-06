<?php

namespace App\Support;

use App\Enums\AiGuidanceType;
use InvalidArgumentException;

/**
 * Strict internal contract for a future virtual front desk response.
 *
 * The payload is parsed only. clinic_id is not treated as bookable or even
 * as an allowed clinic until AiHospitalContextService validates it against
 * the request context and the current database.
 *
 * Doctor selection is not part of this contract.
 */
final readonly class AiGuidanceResult
{
    public const int MAX_TEXT_LENGTH = 2000;

    private function __construct(
        public AiGuidanceType $type,
        public ?int $clinicId,
        public ?string $reason,
        public ?float $confidence,
        public ?string $question,
        public ?string $message,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        $typeValue = $payload['type'] ?? null;
        $type = is_string($typeValue) ? AiGuidanceType::tryFrom($typeValue) : null;

        if (! $type instanceof AiGuidanceType) {
            throw new InvalidArgumentException('AI guidance type is not recognized.');
        }

        return match ($type) {
            AiGuidanceType::Recommendation => self::recommendation($payload),
            AiGuidanceType::Clarification => self::clarification($payload),
            AiGuidanceType::Uncertain => self::uncertain($payload),
            AiGuidanceType::Safety => self::safety($payload),
        };
    }

    /**
     * @return array<string, int|float|string|null>
     */
    public function toArray(): array
    {
        $payload = match ($this->type) {
            AiGuidanceType::Recommendation => [
                'type' => $this->type->value,
                'clinic_id' => $this->clinicId,
                'reason' => $this->reason,
                'confidence' => $this->confidence,
            ],
            AiGuidanceType::Clarification => [
                'type' => $this->type->value,
                'question' => $this->question,
            ],
            AiGuidanceType::Uncertain, AiGuidanceType::Safety => [
                'type' => $this->type->value,
                'message' => $this->message,
            ],
        };

        return array_filter(
            $payload,
            fn (int|float|string|null $value): bool => $value !== null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function recommendation(array $payload): self
    {
        return new self(
            type: AiGuidanceType::Recommendation,
            clinicId: self::requiredClinicId($payload['clinic_id'] ?? null),
            reason: self::requiredText($payload['reason'] ?? null, 'reason'),
            confidence: self::optionalConfidence($payload),
            question: null,
            message: null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function clarification(array $payload): self
    {
        return new self(
            type: AiGuidanceType::Clarification,
            clinicId: null,
            reason: null,
            confidence: null,
            question: self::requiredText($payload['question'] ?? null, 'question'),
            message: null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function uncertain(array $payload): self
    {
        return new self(
            type: AiGuidanceType::Uncertain,
            clinicId: null,
            reason: null,
            confidence: null,
            question: null,
            message: self::requiredText($payload['message'] ?? null, 'message'),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function safety(array $payload): self
    {
        return new self(
            type: AiGuidanceType::Safety,
            clinicId: null,
            reason: null,
            confidence: null,
            question: null,
            message: self::requiredText($payload['message'] ?? null, 'message'),
        );
    }

    private static function requiredClinicId(mixed $clinicId): int
    {
        if (is_int($clinicId) && $clinicId > 0) {
            return $clinicId;
        }

        if (is_string($clinicId) && preg_match('/^[1-9]\d*$/', $clinicId) === 1) {
            return (int) $clinicId;
        }

        throw new InvalidArgumentException('AI guidance clinic_id must be a positive integer.');
    }

    private static function requiredText(mixed $value, string $field): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("AI guidance {$field} is required.");
        }

        $text = trim($value);

        if ($text === '' || mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            throw new InvalidArgumentException("AI guidance {$field} is required.");
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function optionalConfidence(array $payload): ?float
    {
        if (! array_key_exists('confidence', $payload) || $payload['confidence'] === null) {
            return null;
        }

        $value = $payload['confidence'];

        if (is_string($value) && is_numeric($value)) {
            $value = $value + 0;
        }

        if (! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException('AI guidance confidence must be a number between 0 and 1.');
        }

        if (! is_finite($value) || $value < 0 || $value > 1) {
            throw new InvalidArgumentException('AI guidance confidence must be a number between 0 and 1.');
        }

        return (float) $value;
    }
}
