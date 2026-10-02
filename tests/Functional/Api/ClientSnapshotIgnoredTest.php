<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\RequestApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ClientSnapshotIgnoredTest extends WebTestCase
{
    use ApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use RequestApiFixtures;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    #[TestDox('A snapshot in the request body is ignored')]
    public function testSnapshotInBodyIsIgnored(): void
    {
        $this->useTemporaryLocalSources();
        $source = $this->newSource();
        $source->commit(self::codeFiles());
        $client = static::createClient();
        self::signIn($client);
        $reviewId = self::startCodeReview($client);
        $range = ['startLine' => 20, 'endLine' => 21];
        $forged = [
            'commitSha' => str_repeat('a', 40),
            'blobSha' => str_repeat('b', 40),
            'snapshot' => ['lines' => ['echo "forged";', ''], 'truncated' => false, 'lineCount' => 2, 'before' => [], 'after' => [], 'normalizedHash' => 'sha256:'.str_repeat('0', 64)],
        ];

        $opened = self::openRequest($client, $reviewId, ['kind' => 'range', 'path' => self::INVOICE_TOTALS, 'range' => $range, ...$forged]);

        self::assertSame($source->revParse('main'), $opened['anchor']['commitSha']);
        self::assertSame($source->revParse('main:'.self::INVOICE_TOTALS), $opened['anchor']['blobSha']);
        self::assertSame(['    $total += 20;', '    $total += 21;'], $opened['anchor']['snapshot']['lines']);
        self::assertSame('sha256:'.hash('sha256', "\$total += 20;\n\$total += 21;"), $opened['anchor']['snapshot']['normalizedHash']);
    }
}
