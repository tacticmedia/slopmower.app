<?php

declare(strict_types=1);

namespace App\Domain\Issue;

/**
 * What an issue is (D133). Bug: something that exists is wrong against its specification, its documentation, or its
 * evident intent. Change: all else.
 */
enum IssueCategory: string
{
    case Bug = 'bug';
    case Change = 'change';
}
