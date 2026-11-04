<?php

namespace App\Support;

/**
 * Temporary structured summary of one AI Front Desk visit.
 *
 * This is not a medical record, diagnosis, or triage score.
 * Fields are optional and concern-dependent. Missing values are not
 * negative findings. The object is rebuilt from visitor turns on each
 * request so session tampering cannot invent symptoms.
 */
final readonly class AiConversationFacts
{
    /**
     * @param  list<string>  $associated
     * @param  list<string>  $denied
     * @param  list<string>  $unknown
     * @param  list<string>  $answeredTopics
     */
    public function __construct(
        public ?string $primaryConcern,
        public string $concernKind,
        public ?string $bodyLocation,
        public ?string $locationAnchor,
        public ?string $duration,
        public ?string $onset,
        public ?string $severity,
        public ?string $frequency,
        public array $associated,
        public array $denied,
        public array $unknown,
        public array $answeredTopics,
        public ?string $lastQuestionTopic,
        public string $replyLanguage,
        public ?string $missingPriority,
    ) {}

    public static function empty(): self
    {
        return new self(
            primaryConcern: null,
            concernKind: 'other',
            bodyLocation: null,
            locationAnchor: null,
            duration: null,
            onset: null,
            severity: null,
            frequency: null,
            associated: [],
            denied: [],
            unknown: [],
            answeredTopics: [],
            lastQuestionTopic: null,
            replyLanguage: 'english',
            missingPriority: null,
        );
    }

    public function prefersFilipino(): bool
    {
        return $this->replyLanguage === 'filipino' || $this->replyLanguage === 'taglish';
    }

    public function hasTopic(string $topic): bool
    {
        return in_array($topic, $this->answeredTopics, true);
    }

    public function isUnknown(string $topic): bool
    {
        return in_array($topic, $this->unknown, true);
    }

    public function isDenied(string $topic): bool
    {
        return in_array($topic, $this->denied, true);
    }

    public function hasUsableDuration(): bool
    {
        return $this->duration !== null && ! $this->isUnknown('duration');
    }

    public function hasUsableLocation(): bool
    {
        if ($this->bodyLocation === null || $this->isUnknown('location')) {
            return false;
        }

        if ($this->concernKind === 'back' && ($this->bodyLocation === 'back' || $this->bodyLocation === '')) {
            return false;
        }

        return true;
    }

    public function hasUsableSeverity(): bool
    {
        return $this->severity !== null && ! $this->isUnknown('severity');
    }

    public function severityStatus(): string
    {
        if ($this->isUnknown('severity')) {
            return 'unknown';
        }

        if ($this->isDenied('severity')) {
            return 'denied';
        }

        if ($this->hasUsableSeverity()) {
            return 'reported';
        }

        return 'missing';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'primary_concern' => $this->primaryConcern,
            'concern_kind' => $this->concernKind,
            'body_location' => $this->bodyLocation,
            'location_anchor' => $this->locationAnchor,
            'duration' => $this->duration,
            'onset' => $this->onset,
            'severity' => $this->severity,
            'frequency' => $this->frequency,
            'associated_symptoms' => $this->associated,
            'denied' => $this->denied,
            'unknown' => $this->unknown,
            'answered_topics' => $this->answeredTopics,
            'last_question_topic' => $this->lastQuestionTopic,
            'reply_language' => $this->replyLanguage,
            'missing_priority' => $this->missingPriority,
            'note' => 'Missing fields are not negative findings. Unknown is not a denial. Short answers refer to last_question_topic about primary_concern. Location is interpreted relative to the opening concern. Sudden onset is not sudden maximum-intensity pain. Mild or unknown severity does not mean the visitor is medically safe. Approximate duration such as ilang buwan or matagal na is an answer, not unknown. Generic back or abdomen is not a precise location. A valid service_id is not proof that routine navigation is ready. Do not repeat a question whose topic is already answered. Keep reply_language unless the visitor explicitly asks to switch.',
        ];
    }
}
