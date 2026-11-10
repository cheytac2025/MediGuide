<?php

namespace App\ClinicalKnowledge;

use InvalidArgumentException;

/**
 * Version-controlled evidence summaries. Invalid rows are refused.
 */
final class ClinicalKnowledgeCatalog
{
    /**
     * @param  list<ClinicalKnowledgeEntry>  $entries
     */
    private function __construct(private array $entries) {}

    public static function published(): self
    {
        $path = resource_path('clinical-knowledge/entries.php');
        $rows = is_file($path) ? require $path : null;

        if (! is_array($rows)) {
            throw new InvalidArgumentException('The clinical knowledge catalog is missing.');
        }

        return self::fromRows($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public static function fromRows(array $rows): self
    {
        $entries = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('A knowledge entry is not a record.');
            }

            $entry = ClinicalKnowledgeEntry::fromRow($row);

            if (isset($entries[$entry->id])) {
                throw new InvalidArgumentException('A knowledge entry id is duplicated.');
            }

            $entries[$entry->id] = $entry;
        }

        return new self(array_values($entries));
    }

    /**
     * @return list<ClinicalKnowledgeEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public static function containing(ClinicalKnowledgeEntry $entry): self
    {
        return new self([$entry]);
    }

    public function find(string $id): ?ClinicalKnowledgeEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->id === $id) {
                return $entry;
            }
        }

        return null;
    }
}
