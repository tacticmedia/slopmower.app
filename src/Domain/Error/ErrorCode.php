<?php

declare(strict_types=1);

namespace App\Domain\Error;

enum ErrorCode: string
{
    case Unauthorized = 'unauthorized';
    case Forbidden = 'forbidden';
    case NotFound = 'not_found';
    case InvalidInput = 'invalid_input';
    case InvalidTransition = 'invalid_transition';
    case ReviewClosed = 'review_closed';
    case CommitsNotAvailable = 'commits_not_available';
    case CommitNotFound = 'commit_not_found';
    case RepositoryUnavailable = 'repository_unavailable';
    case InternalError = 'internal_error';
}
