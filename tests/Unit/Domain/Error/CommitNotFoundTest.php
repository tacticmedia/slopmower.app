<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Error;

use App\Domain\Error\CommitNotFound;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CommitNotFoundTest extends KernelTestCase
{
    #[TestDox('Keeps the commit as the caller gave it')]
    public function testKeepsTheCommitAsGiven(): void
    {
        $error = new CommitNotFound('feature/x');

        self::assertSame(['commit' => 'feature/x'], $error->details());
        self::assertSame('Commit feature/x is not in the server clone after a fetch.', $error->getMessage());
    }
}
