<?php

declare(strict_types=1);

namespace App\Entity;

enum SignInMethod: string
{
    case GitHub = 'github';
    case Local = 'local';
}
