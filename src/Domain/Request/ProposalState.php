<?php

declare(strict_types=1);

namespace App\Domain\Request;

enum ProposalState: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
