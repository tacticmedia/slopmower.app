<?php

declare(strict_types=1);

/*
 * Appends review.started events through the test kernel, for the concurrency test.
 * Arguments: <tenantId> <repositoryId> <writer name> <event count>. The review titles are "<writer name> <index>".
 * Prints "ready" after the boot. The test holds the tenant row lock until every writer is ready.
 */

use App\Application\ReviewCommands;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RefName;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Domain\Review\Command\StartReview;
use App\Tenancy\TenantContext;
use App\Tests\Support\Domain\RequestScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Dotenv\Dotenv;

$projectDir = dirname(__DIR__, 3);
require $projectDir.'/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? null;

if (!is_array($arguments) || 5 !== count($arguments)) {
    throw new InvalidArgumentException('Arguments: <tenantId> <repositoryId> <writer name> <event count>');
}

[, $tenantId, $repositoryId, $writer, $eventCount] = array_map(strval(...), $arguments);
new Dotenv()->bootEnv($projectDir.'/.env');
// The writers start more reviews than the free plan allows open.
$_ENV['APP_PLAN_FREE_OPEN_REVIEWS'] = $_SERVER['APP_PLAN_FREE_OPEN_REVIEWS'] = '0';

// KernelTestCase boots the test kernel and gives the test container, as in a test.
$kernel = new class('appendEvents') extends KernelTestCase {
    public static function appendEvents(TenantId $tenantId, RepositoryId $repositoryId, string $writer, int $eventCount): void
    {
        self::getContainer()->get(TenantContext::class)->set($tenantId);
        fwrite(\STDOUT, "ready\n");

        for ($index = 1; $index <= $eventCount; ++$index) {
            $command = new StartReview("{$writer} {$index}", RefName::fromString('main'), CommitSha::fromString('79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091'));
            self::getContainer()->get(ReviewCommands::class)->recordStarted(ReviewId::generate(), $repositoryId, $command, RequestScenario::user());
        }

        self::ensureKernelShutdown();
    }
};

$kernel::appendEvents(TenantId::fromString($tenantId), RepositoryId::fromString($repositoryId), $writer, (int) $eventCount);
