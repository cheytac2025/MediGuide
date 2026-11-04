<?php

namespace App\Support;

/**
 * Software policy for whether limited hospital navigation may proceed.
 *
 * This is not a clinical risk score, diagnosis, or proof that a visitor is
 * medically safe. Missing fields are not negative findings. Qualified
 * hospital personnel must review this policy before real-world use.
 */
final class AiRecommendationReadiness
{
    public function isReady(AiConversationFacts $facts): bool
    {
        return $this->blockingTopic($facts) === null;
    }

    public function blockingTopic(AiConversationFacts $facts): ?string
    {
        if ($this->needsPrimaryConcern($facts)) {
            return 'primary';
        }

        if ($this->needsPreciseLocation($facts)) {
            return $facts->concernKind === 'abdominal'
                ? AiConversationInterpreter::TOPIC_QUADRANT
                : AiConversationInterpreter::TOPIC_LOCATION;
        }

        if (! $this->hasBaselineRoutingDetail($facts)) {
            return $facts->missingPriority ?? AiConversationInterpreter::TOPIC_DURATION;
        }

        return null;
    }

    private function needsPrimaryConcern(AiConversationFacts $facts): bool
    {
        return $facts->concernKind === 'multiple'
            && ! $facts->hasTopic('primary')
            && ! $this->hasBaselineRoutingDetail($facts);
    }

    private function needsPreciseLocation(AiConversationFacts $facts): bool
    {
        if ($facts->isUnknown(AiConversationInterpreter::TOPIC_LOCATION)
            || $facts->isDenied(AiConversationInterpreter::TOPIC_LOCATION)
            || $facts->isUnknown(AiConversationInterpreter::TOPIC_QUADRANT)
            || $facts->isDenied(AiConversationInterpreter::TOPIC_QUADRANT)) {
            return false;
        }

        return match ($facts->concernKind) {
            'back', 'skin', 'injury', 'joint' => ! $facts->hasUsableLocation(),
            'abdominal' => ! $this->hasAbdominalRegion($facts),
            default => false,
        };
    }

    private function hasAbdominalRegion(AiConversationFacts $facts): bool
    {
        if ($facts->hasTopic(AiConversationInterpreter::TOPIC_QUADRANT)) {
            return true;
        }

        $location = (string) $facts->bodyLocation;

        return str_contains($location, 'lower') || str_contains($location, 'upper');
    }

    private function hasBaselineRoutingDetail(AiConversationFacts $facts): bool
    {
        return $facts->hasUsableDuration()
            || $facts->hasUsableLocation()
            || $facts->hasUsableSeverity()
            || $facts->hasTopic(AiConversationInterpreter::TOPIC_ASSOCIATED)
            || $facts->hasTopic(AiConversationInterpreter::TOPIC_INJURY)
            || $facts->hasTopic('primary');
    }
}
