<?php

declare(strict_types=1);

namespace App\Domain\Review;

enum Workspace: string
{
    case CurrentBranch = 'current_branch';
    case NewBranch = 'new_branch';
    case Worktree = 'worktree';
}
