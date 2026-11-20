<?php

namespace App\Enums;

enum AccountInvitationPurpose: string
{
    case Activation = 'activation';
    case Claim = 'claim';
}
