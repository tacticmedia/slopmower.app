<?php

declare(strict_types=1);

namespace App\Domain\Request;

enum RequestType: string
{
    case Change = 'change';
    case Question = 'question';
    case Rule = 'rule';
}
