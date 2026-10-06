<?php

namespace App\Enums;

enum AiGuidanceType: string
{
    case Recommendation = 'recommendation';
    case Clarification = 'clarification';
    case Uncertain = 'uncertain';
    case Safety = 'safety';
}
