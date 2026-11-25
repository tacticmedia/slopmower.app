<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Issue;

use App\Domain\Issue\IssuePriority;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class IssuePriorityTest extends KernelTestCase
{
    #[TestDox('Of 3 open issues, a share that is no whole number of issues rounds up')]
    public function testShareRoundsUp(): void
    {
        self::assertSame(
            ['top' => 0, 'urgent' => 1, 'high' => 1, 'normal' => 2, 'low' => 3, 'none' => 3],
            array_combine(array_column(IssuePriority::cases(), 'value'), array_map(static fn (IssuePriority $priority): int => $priority->issuesBefore(3), IssuePriority::cases())),
        );
    }
}
