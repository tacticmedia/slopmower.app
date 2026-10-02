<?php

declare(strict_types=1);

namespace App\Git;

enum ObjectType: string
{
    case Blob = 'blob';
    case Tree = 'tree';
    case Commit = 'commit';
    case Tag = 'tag';
}
