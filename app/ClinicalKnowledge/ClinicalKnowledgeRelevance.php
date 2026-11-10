<?php

namespace App\ClinicalKnowledge;

/**
 * Evidence-retrieval categories. They are not urgency levels and they
 * do not mean a concern is safe or diagnosed.
 */
final class ClinicalKnowledgeRelevance
{
    public const string RELEVANT = 'relevant';

    public const string POSSIBLY_RELEVANT = 'possibly_relevant';

    public const string IRRELEVANT = 'irrelevant';

    public const string INSUFFICIENT_INFORMATION = 'insufficient_information';

    public const string POPULATION_INAPPLICABLE = 'population_inapplicable';

    public const string STALE_OR_UNSUPPORTED = 'stale_or_unsupported';

    public const string UNASSESSED = 'unassessed';

    /**
     * Levels an independent judge may return. Population and stale status
     * stay with the gate, so a judge cannot reopen them.
     *
     * @return list<string>
     */
    public static function judgeLevels(): array
    {
        return [
            self::RELEVANT,
            self::POSSIBLY_RELEVANT,
            self::IRRELEVANT,
            self::INSUFFICIENT_INFORMATION,
            self::UNASSESSED,
        ];
    }
}
