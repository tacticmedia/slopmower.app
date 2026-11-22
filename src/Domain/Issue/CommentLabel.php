<?php

declare(strict_types=1);

namespace App\Domain\Issue;

/**
 * What a comment of an issue is (D121).
 */
enum CommentLabel: string
{
    case Comment = 'comment';
    case Decision = 'decision';
    case Question = 'question';
    case Finding = 'finding';
    case Deviation = 'deviation';
    case ScopeCreep = 'scope_creep';
    case Papercut = 'papercut';
    case Review = 'review';
}
