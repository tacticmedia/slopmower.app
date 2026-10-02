<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Git\PinRefs;
use App\Tests\Support\Api\AgentApiFixtures;
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
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class PendingLinkResolutionTest extends WebTestCase
{
    use AgentApiFixtures;
    use ApiFixtures;
    use ClockSensitiveTrait;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use RequestApiFixtures;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    #[TestDox('A later fetch resolves the pending link with commit.found by the system and a pin')]
    public function testLaterFetchResolvesPendingLink(): void
    {
        $this->useTemporaryLocalSources();
        $source = $this->newSource();
        $source->commit(self::codeFiles());
        $client = static::createClient();
        $user = self::signIn($client);
        $repositoryId = self::connectAndClone($client);
        $requestId = (string) self::openRequest($client, (string) self::startReviewThroughApi($client, $repositoryId)['id'])['id'];
        [, $secret] = self::storeToken($user);
        self::agentRequest($client, $secret, 'POST', "/api/v1/requests/{$requestId}/claim");
        $fix = $source->switch('fix')->commit(['src/Billing/TaxRounding.php' => "<?php\n\nfinal class TaxRounding\n{\n    public const int SCALE = 2;\n}\n"], 'Extract TaxRounding');
        self::agentRequest($client, $secret, 'POST', '/api/v1/commit-links', ['commitSha' => $fix, 'requestIds' => [$requestId]], schema: 'link-commits-result.schema.json');

        // After the delay of the fetch job.
        self::mockTime('+6 seconds');
        self::runQueuedMessages();

        $request = self::readRequest($client, $requestId);
        self::assertSame(['available', 'Extract TaxRounding', 0], [$request['commits'][0]['state'], $request['commits'][0]['subject'], $request['pendingCommitCount']]);
        self::assertIsString($request['commits'][0]['foundAt']);
        $found = array_values(array_filter($request['thread'], static fn (array $entry): bool => 'commit.found' === $entry['type']));
        self::assertSame([['system', $fix]], array_map(static fn (array $entry): array => [$entry['actor']['kind'], $entry['commitSha']], $found));
        self::assertSame($fix, self::pins($user, $repositoryId)[PinRefs::COMMITS.$fix] ?? null);
    }
}
