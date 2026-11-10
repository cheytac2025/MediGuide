<?php

namespace App\ClinicalKnowledge;

/**
 * Provisional engineering labels for relevance evaluation.
 *
 * These are not clinician ratings and they are not live Claude output.
 * The proposal list and the judgment are stored separately so a selector
 * cannot grade itself.
 */
final class EngineeringRelevanceLabels
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function scenarios(): array
    {
        return [
            self::row('filipino-vomiting', 'filipino', 'familiar', 'Nagsusuka ako.', ['medlineplus-nausea-vomiting'], ['medlineplus-nausea-vomiting' => 'relevant'], 'relevant'),
            self::row('english-chest', 'english', 'familiar', 'My chest feels weird.', ['medlineplus-chest-pain'], ['medlineplus-chest-pain' => 'relevant'], 'relevant'),
            self::row('english-headache', 'english', 'familiar', "I've had a headache since yesterday.", ['medlineplus-headache'], ['medlineplus-headache' => 'relevant'], 'relevant'),
            self::row('taglish-headache', 'taglish', 'taglish', 'My ulo hurts.', ['medlineplus-headache'], ['medlineplus-headache' => 'relevant'], 'relevant'),
            self::row('informal-headache', 'filipino', 'informal', 'Masaket ulo ko.', ['medlineplus-headache'], ['medlineplus-headache' => 'relevant'], 'relevant'),
            self::row('multiple-concerns', 'filipino', 'multiple', 'May ubo ako at masakit ang ulo ko.', ['medlineplus-cough', 'medlineplus-headache'], ['medlineplus-cough' => 'relevant', 'medlineplus-headache' => 'relevant'], 'relevant'),
            self::row('chronic-back', 'filipino', 'chronic', 'Masakit ang likod ko nang ilang buwan na.', ['medlineplus-back-pain'], ['medlineplus-back-pain' => 'relevant'], 'relevant'),
            self::row('intermittent-headache', 'taglish', 'intermittent', 'Sobrang sakit ng ulo bigla-bigla since last week.', ['medlineplus-headache'], ['medlineplus-headache' => 'relevant'], 'relevant'),
            self::row('knee-injury', 'filipino', 'injury', 'Nahulog ako at masakit ang tuhod ko.', ['medlineplus-knee-injuries'], ['medlineplus-knee-injuries' => 'relevant'], 'relevant'),
            self::row('skin-change', 'filipino', 'skin', 'May batik sa braso ko.', ['medlineplus-rashes'], ['medlineplus-rashes' => 'relevant'], 'relevant'),
            self::row('sleep', 'filipino', 'sleep', 'Ilang gabi na akong hindi makatulog.', ['medlineplus-insomnia'], ['medlineplus-insomnia' => 'relevant'], 'relevant'),
            self::row('emotional', 'filipino', 'emotional', 'Parang laging balisa ako.', ['medlineplus-anxiety'], ['medlineplus-anxiety' => 'relevant'], 'relevant'),
            self::row('pediatric-fever', 'filipino', 'pediatric', 'Ang anak ko ay may lagnat.', ['medlineplus-fever'], ['medlineplus-fever' => 'relevant'], 'relevant'),
            self::row('pregnancy-back', 'filipino', 'pregnancy', 'Buntis ako at masakit ang likod.', ['medlineplus-back-pain'], ['medlineplus-back-pain' => 'relevant'], 'relevant'),
            self::row('adult-headache', 'english', 'adult', 'I am an adult and I have a headache.', ['medlineplus-headache', 'nice-cg150-headache-assessment'], ['medlineplus-headache' => 'relevant', 'nice-cg150-headache-assessment' => 'relevant'], 'relevant'),
            self::row('fever-for-knee', 'filipino', 'irrelevant', 'Nahulog ako at masakit ang tuhod ko.', ['medlineplus-fever'], ['medlineplus-fever' => 'irrelevant'], 'irrelevant'),
            self::row('fever-for-chest', 'english', 'irrelevant', 'My chest feels weird.', ['medlineplus-fever'], ['medlineplus-fever' => 'irrelevant'], 'irrelevant'),
            self::row('knee-for-vomiting', 'filipino', 'irrelevant', 'Nagsusuka ako.', ['medlineplus-knee-injuries'], ['medlineplus-knee-injuries' => 'irrelevant'], 'irrelevant'),
            self::row('cough-for-anxiety', 'filipino', 'irrelevant', 'Parang laging balisa ako.', ['medlineplus-cough'], ['medlineplus-cough' => 'irrelevant'], 'irrelevant'),
            self::row('headache-for-rash', 'filipino', 'irrelevant', 'May batik sa braso ko.', ['medlineplus-headache'], ['medlineplus-headache' => 'irrelevant'], 'irrelevant'),
            self::row('chest-for-sleep', 'filipino', 'irrelevant', 'Ilang gabi na akong hindi makatulog.', ['medlineplus-chest-pain'], ['medlineplus-chest-pain' => 'irrelevant'], 'irrelevant'),
            self::row('nausea-for-knee', 'filipino', 'irrelevant', 'Nahulog ako at masakit ang tuhod ko.', ['medlineplus-nausea-vomiting'], ['medlineplus-nausea-vomiting' => 'irrelevant'], 'irrelevant'),
            self::row('headache-for-unfamiliar', 'filipino', 'irrelevant', 'Parang may kumikirot sa tagiliran ko.', ['medlineplus-headache'], ['medlineplus-headache' => 'irrelevant'], 'irrelevant'),
            self::row('ambiguous-sensation', 'filipino', 'ambiguous', 'May kakaibang pakiramdam sa katawan ko.', ['medlineplus-anxiety'], ['medlineplus-anxiety' => 'possibly_relevant'], 'possibly_relevant'),
            self::row('insufficient-pain', 'filipino', 'insufficient', 'Masakit.', ['medlineplus-headache'], ['medlineplus-headache' => 'insufficient_information'], 'insufficient_information'),
            self::row('no-source', 'filipino', 'unfamiliar', 'Parang may kumikirot sa tagiliran ko.', [], [], 'gap'),
            self::row('child-dizziness', 'filipino', 'population', 'Ang anak ko ay nahihilo.', ['nice-ng127-sudden-dizziness'], ['nice-ng127-sudden-dizziness' => 'relevant'], 'population'),
            self::row('age-unknown-guideline', 'english', 'population', "I've had a headache since yesterday.", ['nice-cg150-headache-assessment'], ['nice-cg150-headache-assessment' => 'relevant'], 'population'),
            self::row('denial', 'filipino', 'denial', 'Hindi ako nagsusuka ng dugo.', ['medlineplus-nausea-vomiting'], ['medlineplus-nausea-vomiting' => 'relevant'], 'withheld'),
            self::row('unknown-answer', 'filipino', 'unknown', 'Hindi ko alam.', ['medlineplus-headache'], ['medlineplus-headache' => 'relevant'], 'withheld'),
            self::row('navigation', 'english', 'navigation', "I'm not sure which specialist I need", ['medlineplus-headache'], ['medlineplus-headache' => 'relevant'], 'withheld'),
            self::row('invented-id', 'filipino', 'hallucination', 'Nagsusuka ako.', ['chest-specialist-9'], [], 'invented'),
            self::row('stale-manual', 'english', 'stale', 'The child needs emergency triage at the hospital.', ['who-etat-2005'], ['who-etat-2005' => 'relevant'], 'stale'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $id): ?array
    {
        foreach (self::scenarios() as $scenario) {
            if ($scenario['id'] === $id) {
                return $scenario;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $proposal
     * @param  array<string, string>  $judgments
     * @return array<string, mixed>
     */
    private static function row(
        string $id,
        string $language,
        string $category,
        string $text,
        array $proposal,
        array $judgments,
        string $expected,
    ): array {
        return [
            'id' => $id,
            'language' => $language,
            'category' => $category,
            'text' => $text,
            'proposal' => $proposal,
            'judgments' => $judgments,
            'expected' => $expected,
        ];
    }
}
