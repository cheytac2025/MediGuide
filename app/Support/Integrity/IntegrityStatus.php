<?php

namespace App\Support\Integrity;

enum IntegrityStatus: string
{
    case Valid = 'valid';
    case Unsigned = 'unsigned';
    case Invalid = 'invalid';
}
