<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * A part of the review tour of the web UI. An open set (D77).
 */
enum Tour: string
{
    case CodeBrowser = 'code_browser';
    case File = 'file';
}
