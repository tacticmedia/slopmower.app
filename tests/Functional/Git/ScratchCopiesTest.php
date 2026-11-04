<?php

declare(strict_types=1);

namespace App\Tests\Functional\Git;

use App\Git\ScratchCopies;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class ScratchCopiesTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use EnvironmentVariables;
    use TemporaryDirectories;

    #[TestDox('A scratch directory older than 1 hour is gone after the next scratch copy, and a younger one stays')]
    public function testNextCopyRemovesLeftovers(): void
    {
        $now = self::mockTime('2026-11-04 10:00:00')->now()->getTimestamp();
        $scratch = $this->temporaryDirectory();
        $this->setEnvironmentVariable('APP_SCRATCH_DIR', $scratch);
        mkdir("{$scratch}/old.git");
        mkdir("{$scratch}/young.git");
        touch("{$scratch}/old.git", $now - 3601);
        touch("{$scratch}/young.git", $now - 3599);

        $copy = self::getContainer()->get(ScratchCopies::class)->emptyRepository();

        self::assertDirectoryDoesNotExist("{$scratch}/old.git");
        self::assertDirectoryExists("{$scratch}/young.git");
        self::assertDirectoryExists($copy->gitDir);
    }
}
