<?php

declare(strict_types=1);

namespace App\Domain\User;

enum SignInMethod: string
{
    case GitHub = 'github';
    case Local = 'local';
}
