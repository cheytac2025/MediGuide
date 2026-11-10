<?php

namespace App\ClinicalKnowledge;

/**
 * Blank reviewer rows for the existing engineering scenarios.
 *
 * Clinical rating cells stay empty. Engineering labels are identified as
 * engineering labels and are not reviewer ratings.
 */
final class ClinicalKnowledgeReviewPackage
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function pairs(ClinicalKnowledgeCatalog $catalog): array
    {
        $pairs = [];

        foreach (EngineeringRelevanceLabels::scenarios() as $scenario) {
            $sources = $scenario['proposal'] === [] ? [null] : $scenario['proposal'];

            foreach ($sources as $sourceId) {
                $entry = is_string($sourceId) ? $catalog->find($sourceId) : null;
                $judgment = is_string($sourceId) ? ($scenario['judgments'][$sourceId] ?? null) : null;
                $pairs[] = [
                    'scenario_id' => $scenario['id'],
                    'text' => $scenario['text'],
                    'language' => $scenario['language'],
                    'category' => $scenario['category'],
                    'source_id' => $sourceId,
                    'in_catalog' => $entry !== null,
                    'organization' => $entry?->organization,
                    'title' => $entry?->title,
                    'url' => $entry?->url,
                    'population' => $entry?->population,
                    'revised_on' => $entry?->revisedOn,
                    'engineering_label' => is_string($judgment) ? $judgment : null,
                    'software_handling' => $scenario['expected'],
                ];
            }
        }

        return $pairs;
    }

    public static function worksheetMarkdown(ClinicalKnowledgeCatalog $catalog): string
    {
        $lines = [
            '# Stage P.3 relevance worksheet',
            '',
            '**MediGuide AI Virtual Front Desk**',
            '',
            '**Hospital context:** Queen Mary Help of Christians Hospital, Inc., Cardona, Rizal',
            '',
            '**Status:** Not clinically reviewed. Clinical rating cells are blank. This worksheet does not say the hospital has approved MediGuide.',
            '',
            'Synthetic concerns only. Engineering labels are software labels, not reviewer ratings. Leave every clinical cell blank until you complete it.',
            '',
            'Relevance choices: Relevant, Possibly Relevant, Irrelevant, Insufficient Information, Population Inapplicable, Stale or Unsupported, Not Evaluated.',
            '',
            'Safety choices, recorded separately: Appropriate, Needs Revision, Unsafe, Not Applicable, Not Evaluated.',
            '',
            'Applicability choices: Applicable, Limited, Not Applicable, Not Evaluated.',
            '',
            'A relevant source is not proof that a concern is medically safe.',
            '',
        ];

        $number = 0;

        foreach (self::pairs($catalog) as $pair) {
            $number++;
            $source = $pair['source_id'] ?? 'None proposed';
            $lines[] = '## '.$number.'. '.$pair['scenario_id'].' — '.$source;
            $lines[] = '';
            $lines[] = '| Field | Record |';
            $lines[] = '| --- | --- |';
            $lines[] = '| Scenario ID | '.$pair['scenario_id'].' |';
            $lines[] = '| Synthetic patient concern | '.$pair['text'].' |';
            $lines[] = '| Language | '.$pair['language'].' |';
            $lines[] = '| Evaluation category | '.$pair['category'].' |';
            $lines[] = '| Proposed source ID | '.$source.' |';
            $lines[] = '| Source organization | '.self::cell($pair['organization']).' |';
            $lines[] = '| Source title | '.self::cell($pair['title']).' |';
            $lines[] = '| Source URL | '.self::cell($pair['url']).' |';
            $lines[] = '| Applicable population | '.self::cell($pair['population']).' |';
            $lines[] = '| Source revision date | '.self::revision($pair).' |';
            $lines[] = '| Engineering relevance label | '.self::engineering($pair).' |';
            $lines[] = '| Isolated test handling | '.self::handling($pair['software_handling']).' |';
            $lines[] = '| Clinical reviewer relevance assessment | |';
            $lines[] = '| Clinical applicability assessment | |';
            $lines[] = '| Safety concerns | |';
            $lines[] = '| Reviewer comments | |';
            $lines[] = '';
        }

        $lines[] = '## Catalog entries without a scenario pair';
        $lines[] = '';
        $lines[] = 'These entries remain in the unreviewed catalog. No synthetic concern was paired with them, so no clinical rating is requested here.';
        $lines[] = '';
        $lines[] = '| Source ID | Title |';
        $lines[] = '| --- | --- |';

        $used = [];

        foreach (self::pairs($catalog) as $pair) {
            if (is_string($pair['source_id'])) {
                $used[$pair['source_id']] = true;
            }
        }

        foreach ($catalog->entries() as $entry) {
            if (! isset($used[$entry->id])) {
                $lines[] = '| '.$entry->id.' | '.$entry->title.' |';
            }
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $pair
     */
    private static function revision(array $pair): string
    {
        if ($pair['source_id'] === null) {
            return 'No source was proposed';
        }

        if ($pair['in_catalog'] !== true) {
            return 'Not a catalog source';
        }

        return is_string($pair['revised_on']) ? $pair['revised_on'] : 'Not displayed';
    }

    /**
     * @param  array<string, mixed>  $pair
     */
    private static function engineering(array $pair): string
    {
        if (! is_string($pair['engineering_label'])) {
            return 'None stored. This is not a clinical rating.';
        }

        return ClinicalKnowledgeReviewRatings::label($pair['engineering_label']).' (engineering label, not a clinical rating)';
    }

    private static function handling(string $expected): string
    {
        return match ($expected) {
            'relevant' => 'Accepted only when a separate judge supplied the engineering label. Not a clinical rating.',
            'irrelevant' => 'The engineering label says this source does not fit the concern. Not a clinical rating.',
            'possibly_relevant' => 'The engineering label is uncertain. Not a clinical rating.',
            'insufficient_information' => 'The engineering label says the concern is too thin to use the source. Not a clinical rating.',
            'gap' => 'No source was proposed. The gap is not a finding that the concern is harmless.',
            'population' => 'The software withholds this source on population grounds before relevance. Not a clinical rating.',
            'withheld' => 'The software discards this proposal before relevance because of a denial, an unknown answer, or navigation without a described concern.',
            'invented' => 'This proposed ID is not in the catalog and cannot be stored as a reviewed source.',
            'stale' => 'The software keeps this source out of current guidance because the entry is marked stale.',
            default => 'Not a clinical rating.',
        };
    }

    private static function cell(?string $value): string
    {
        return $value === null || $value === '' ? 'Not in the catalog' : $value;
    }
}
