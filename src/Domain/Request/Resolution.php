<?php

declare(strict_types=1);

namespace App\Domain\Request;

enum Resolution: string
{
    case Fixed = 'fixed';
    case WontDo = 'wont_do';
}
