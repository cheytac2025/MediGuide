<?php

namespace Tests\Evaluation\AiSymptomUnderstanding;

use App\Support\AiConversationInterpreter;
use App\Support\AiConversationState;
use App\Support\AiNavigationSafety;
use App\Support\AiRecommendationReadiness;
use App\Support\AiUrgentConcernDetector;
use Tests\TestCase;

/**
 * Offline synthetic evaluation of overlay/interpreter behavior.
 *
 * Mocked / local only — not clinical validation and not live Anthropic.
 */
class SymptomUnderstandingEvaluationTest extends TestCase
{
    public function test_catalog_has_at_least_two_hundred_scenarios(): void
    {
        $this->assertGreaterThanOrEqual(200, count(SymptomScenarioCatalog::scenarios()));
    }

    public function test_offline_symptom_understanding_metrics(): void
    {
        $detector = app(AiUrgentConcernDetector::class);
        $safety = app(AiNavigationSafety::class);
        $readiness = app(AiRecommendationReadiness::class);

        $scenarios = SymptomScenarioCatalog::scenarios();
        $urgentExpected = 0;
        $urgentHit = 0;
        $urgentFalsePositive = 0;
        $kindChecked = 0;
        $kindHit = 0;
        $readyChecked = 0;
        $readyHit = 0;
        $failures = [];

        foreach ($scenarios as $scenario) {
            $text = $scenario['text'];
            $isUrgent = $detector->isUrgent($text)
                || $safety->classify($text, AiConversationState::empty(), $text)->blocksRoutineNavigation();

            if ($scenario['expect_urgent']) {
                $urgentExpected++;
                if ($isUrgent) {
                    $urgentHit++;
                } else {
                    $failures[] = $scenario['id'].': missed urgent';
                }
            } elseif ($isUrgent && $scenario['category'] !== 'emergency') {
                // Allow prompt-evaluation overlays for severe abdominal patterns, etc.
                if (! $this->isAllowedNonEmergencyOverlay($text)) {
                    $urgentFalsePositive++;
                    $failures[] = $scenario['id'].': false urgent';
                }
            }

            $facts = AiConversationInterpreter::interpret(AiConversationState::empty(), $text);

            if ($scenario['expect_kind'] !== null) {
                $kindChecked++;
                if ($facts->concernKind === $scenario['expect_kind']) {
                    $kindHit++;
                } else {
                    $failures[] = $scenario['id'].': kind '.$facts->concernKind.' != '.$scenario['expect_kind'];
                }
            }

            $readyChecked++;
            $ready = $readiness->isReady($facts);
            if ($ready === $scenario['expect_ready']) {
                $readyHit++;
            } else {
                $failures[] = $scenario['id'].': ready '.($ready ? 'true' : 'false').' != '.($scenario['expect_ready'] ? 'true' : 'false');
            }
        }

        $urgentRecall = $urgentExpected > 0 ? $urgentHit / $urgentExpected : 1.0;
        $kindAgreement = $kindChecked > 0 ? $kindHit / $kindChecked : 1.0;
        $readyAgreement = $readyChecked > 0 ? $readyHit / $readyChecked : 1.0;

        // Soft thresholds for synthetic offline harness — not clinical claims.
        $this->assertGreaterThanOrEqual(0.90, $urgentRecall, 'Emergency recall too low: '.implode('; ', array_slice($failures, 0, 12)));
        $this->assertLessThanOrEqual(8, $urgentFalsePositive, 'Too many urgent false positives: '.implode('; ', array_slice($failures, 0, 12)));
        $this->assertGreaterThanOrEqual(0.80, $kindAgreement, 'Concern-kind agreement too low: '.implode('; ', array_slice($failures, 0, 12)));
        $this->assertGreaterThanOrEqual(0.75, $readyAgreement, 'Readiness agreement too low: '.implode('; ', array_slice($failures, 0, 12)));

        // Keep a durable summary artifact for the report.
        $summary = [
            'scenarios' => count($scenarios),
            'urgent_expected' => $urgentExpected,
            'urgent_recall' => round($urgentRecall, 3),
            'urgent_false_positives' => $urgentFalsePositive,
            'kind_agreement' => round($kindAgreement, 3),
            'ready_agreement' => round($readyAgreement, 3),
            'failure_sample' => array_slice($failures, 0, 20),
            'clinical_validation' => 'not claimed',
        ];
        file_put_contents(
            storage_path('app/ai_symptom_eval_summary.json'),
            json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );
    }

    private function isAllowedNonEmergencyOverlay(string $text): bool
    {
        $n = mb_strtolower($text);

        return str_contains($n, 'right') && str_contains($n, 'lower') && (str_contains($n, 'abdomen') || str_contains($n, 'tiyan'));
    }
}
