<?php

declare(strict_types=1);

namespace App\Tests\Functional\Git;

use App\Git\GitFailed;
use App\Git\GitRunner;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;

final class GitRunnerRedactionTest extends KernelTestCase
{
    use TemporaryDirectories;

    private const string SECRET = 'ghs_secretInstallationToken';

    #[TestDox('A secret in the environment never appears in exception messages or logs')]
    public function testSecretIsRedacted(): void
    {
        $logger = new BufferingLogger();
        $git = new GitRunner($logger);
        $clone = $this->temporaryDirectory();
        $git->run($clone, ['init', '--bare', '--quiet']);

        try {
            // Git echoes an invalid numeric value in its error message.
            $git->run($clone, ['rev-parse', 'HEAD'], secretConfiguration: ['core.bigFileThreshold' => self::SECRET]);
            self::fail('Git accepted the invalid value.');
        } catch (GitFailed $failure) {
            $message = $failure->getMessage();
        }

        $logs = serialize($logger->cleanLogs());
        self::assertStringContainsString("bad numeric config value '[redacted]'", $message);
        self::assertStringNotContainsString(self::SECRET, $message);
        self::assertStringContainsString('[redacted]', $logs);
        self::assertStringNotContainsString(self::SECRET, $logs);
    }
}
