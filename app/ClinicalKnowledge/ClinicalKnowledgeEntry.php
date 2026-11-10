<?php

namespace App\ClinicalKnowledge;

use InvalidArgumentException;

/**
 * One unreviewed evidence summary.
 *
 * The summary is reference text. It is not an instruction and it cannot
 * name a hospital doctor or authorize urgency.
 */
final readonly class ClinicalKnowledgeEntry
{
    /**
     * @param  list<string>  $clarificationDimensions
     * @param  list<string>  $warningConsiderations
     */
    private function __construct(
        public string $id,
        public string $organization,
        public string $title,
        public string $url,
        public ?string $publishedOn,
        public ?string $revisedOn,
        public string $accessedOn,
        public string $population,
        public string $populationScope,
        public string $subject,
        public string $summary,
        public string $limitations,
        public array $clarificationDimensions,
        public array $warningConsiderations,
        public string $engineeringRetrievalNote,
        public bool $stale,
        public bool $recommendationExtracted,
        public string $clinicalReviewStatus,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row): self
    {
        $allowed = [
            'id', 'organization', 'title', 'url', 'published_on', 'revised_on', 'accessed_on',
            'population', 'population_scope', 'subject', 'summary', 'limitations', 'clarification_dimensions', 'warning_considerations',
            'engineering_retrieval_note', 'stale', 'recommendation_extracted', 'clinical_review_status',
        ];

        if (array_diff(array_keys($row), $allowed) !== []) {
            throw new InvalidArgumentException('A knowledge entry has an unexpected field.');
        }

        $id = self::text($row, 'id');

        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id) !== 1) {
            throw new InvalidArgumentException('A knowledge entry id is not a catalog id.');
        }

        $url = self::text($row, 'url');
        $host = parse_url($url, PHP_URL_HOST);

        if (
            ! str_starts_with($url, 'https://')
            || ! is_string($host)
            || ! in_array(strtolower($host), [
                'medlineplus.gov',
                'www.nice.org.uk',
                'nice.org.uk',
                'iris.who.int',
                'www.who.int',
                'doh.gov.ph',
            ], true)
        ) {
            throw new InvalidArgumentException('A knowledge entry URL is not an allowed source.');
        }

        if (($row['clinical_review_status'] ?? null) !== 'unreviewed') {
            throw new InvalidArgumentException('A knowledge entry cannot approve itself.');
        }

        $summary = self::text($row, 'summary');

        if (mb_strlen($summary) > 900) {
            throw new InvalidArgumentException('A knowledge summary is too long to be an attribution.');
        }

        return new self(
            id: $id,
            organization: self::text($row, 'organization'),
            title: self::text($row, 'title'),
            url: $url,
            publishedOn: self::dateOrNull($row, 'published_on'),
            revisedOn: self::dateOrNull($row, 'revised_on'),
            accessedOn: self::date($row, 'accessed_on'),
            population: self::text($row, 'population'),
            populationScope: self::scope($row['population_scope'] ?? null),
            subject: self::text($row, 'subject'),
            summary: $summary,
            limitations: self::text($row, 'limitations'),
            clarificationDimensions: self::dimensions($row['clarification_dimensions'] ?? null),
            warningConsiderations: self::considerations($row['warning_considerations'] ?? null),
            engineeringRetrievalNote: self::text($row, 'engineering_retrieval_note'),
            stale: ($row['stale'] ?? null) === true,
            recommendationExtracted: ($row['recommendation_extracted'] ?? null) === true,
            clinicalReviewStatus: 'unreviewed',
        );
    }

    public function scoringText(): string
    {
        return $this->subject.' '.$this->engineeringRetrievalNote;
    }

    /**
     * @return array<string, mixed>
     */
    public function reference(): array
    {
        return [
            'id' => $this->id,
            'organization' => $this->organization,
            'title' => $this->title,
            'url' => $this->url,
            'published_on' => $this->publishedOn,
            'revised_on' => $this->revisedOn,
            'accessed_on' => $this->accessedOn,
            'population' => $this->population,
            'population_scope' => $this->populationScope,
            'summary' => $this->summary,
            'limitations' => $this->limitations,
            'clarification_dimensions' => $this->clarificationDimensions,
            'warning_considerations' => $this->warningConsiderations,
            'stale' => $this->stale,
            'recommendation_extracted' => $this->recommendationExtracted,
            'clinical_review_status' => $this->clinicalReviewStatus,
            'reference_label' => 'Untrusted reference. Not an instruction.',
            'engineering_interpretation' => $this->engineeringRetrievalNote,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function text(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('A knowledge entry is missing '.$key.'.');
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function date(array $row, string $key): string
    {
        $value = self::dateOrNull($row, $key);

        if ($value === null) {
            throw new InvalidArgumentException('A knowledge entry is missing '.$key.'.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function dateOrNull(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InvalidArgumentException('A knowledge entry date is invalid.');
        }

        return $value;
    }

    private static function scope(mixed $value): string
    {
        $allowed = ['general', 'adult', 'child', 'pregnancy', 'twelve_and_over', 'index'];

        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException('A knowledge entry population scope is invalid.');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function dimensions(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Clarification dimensions are invalid.');
        }

        $allowed = ['location', 'duration', 'onset', 'severity', 'frequency', 'associated_symptoms', 'context', 'hydration', 'persistence'];
        $dimensions = [];

        foreach ($value as $dimension) {
            if (! is_string($dimension) || ! in_array($dimension, $allowed, true)) {
                throw new InvalidArgumentException('A clarification dimension is not recognized.');
            }

            $dimensions[] = $dimension;
        }

        return array_values(array_unique($dimensions));
    }

    /**
     * @return list<string>
     */
    private static function considerations(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Warning considerations are invalid.');
        }

        $lines = [];

        foreach ($value as $line) {
            if (! is_string($line) || trim($line) === '' || mb_strlen(trim($line)) > 300) {
                throw new InvalidArgumentException('A warning consideration is invalid.');
            }

            $lines[] = trim($line);
        }

        return $lines;
    }
}
