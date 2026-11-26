<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

/**
 * Input schema parts that several tools share. Each pattern is the one of its definition in
 * docs/specs/schemas/common.schema.json.
 */
final class Input
{
    public const string NO_NUL = '^[^\x00]*$';
    public const string ULID = '^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$';
    public const string ISSUE_KEY = '^[A-Z]{2,10}-[1-9][0-9]{0,8}$';
    public const string PROJECT_KEY = '^[A-Z]{2,10}$';
    public const string COMMIT_SHA = '^([0-9a-f]{40}|[0-9a-f]{64})$';
    public const string COMMIT_SHA_PREFIX = '^[0-9a-f]{7,64}$';
    public const string PULL_REQUEST_URL = '^https://[^\s\x00]+$';

    public const string PROBLEM = 'Markdown, for the user. bug: what is wrong, how to see it, and what is expected. change: what is wanted, and how to tell that it is done. Name no solution; Done when is an outcome that the user can see, not a mechanism. The current understanding: write a new version when an answer, a review, or a finding changes it';

    public const string SESSION = 'Your session name, the same in each write: the directory name of your working tree, a hyphen, and 4 hex digits, in Claude Code the first 4 characters of CLAUDE_CODE_SESSION_ID, else random digits kept in the file slopmower-session of your git directory. A subagent sends the name of the agent that started it.';

    /** The items of `questions`. */
    public const array QUESTION = [
        'type' => 'object',
        'properties' => [
            'question' => ['type' => 'string', 'description' => 'The question, in one line', 'minLength' => 1, 'maxLength' => 200, 'pattern' => self::NO_NUL],
            'detail' => ['type' => ['null', 'string'], 'description' => 'Optional Markdown context of the question', 'default' => null, 'maxLength' => 4000, 'pattern' => self::NO_NUL],
        ],
        'required' => ['question'],
        'additionalProperties' => false,
    ];
}
