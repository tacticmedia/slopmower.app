<?php

declare(strict_types=1);

namespace App\Domain\Issue;

/**
 * What a comment of an issue is (D121). The server sets Question and Answer on a comment with questions (D124).
 */
enum CommentLabel: string
{
    case Comment = 'comment';
    case Decision = 'decision';
    case Question = 'question';
    case Answer = 'answer';
    case Finding = 'finding';
    case Deviation = 'deviation';
    case ScopeCreep = 'scope_creep';
    case Papercut = 'papercut';
    case Review = 'review';
}
