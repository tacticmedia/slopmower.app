<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\DraftApiFixtures;
use App\Tests\Support\Api\RequestApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * F85. `{reviewId}` and `{requestId}` in a URI are replaced.
 */
final class NulByteTest extends WebTestCase
{
    use ApiFixtures;
    use DraftApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use RequestApiFixtures;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private KernelBrowser $client;

    private string $reviewId;

    private string $requestId;

    /**
     * @return array<string, array{string, string, array<string, mixed>|null, string}>
     */
    public static function inputsWithNulByte(): array
    {
        return [
            'a body field' => ['POST', '/api/v1/requests/{requestId}/comments', ['body' => "Keep\0this."], 'body'],
            'a nested body field' => ['POST', '/api/v1/reviews/{reviewId}/drafts', ['kind' => 'new_request', 'anchor' => ['kind' => 'file', 'path' => "READ\0ME.md"], 'body' => 'Typo.'], 'anchor.path'],
            'a query value' => ['GET', '/api/v1/repositories?name=billing%00x', null, 'name'],
        ];
    }

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource()->commit(self::codeFiles());
        $this->client = static::createClient();
        self::signIn($this->client);
        $this->reviewId = (string) self::startReviewThroughApi($this->client, self::connectAndClone($this->client))['id'];
        $this->requestId = (string) self::openRequest($this->client, $this->reviewId)['id'];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('inputsWithNulByte')]
    #[TestDox('A NUL byte in $_dataName gives 422 invalid_input for its field and writes nothing')]
    public function testNulByteIsInvalidInput(string $method, string $uri, ?array $body, string $field): void
    {
        $events = self::eventCount();

        $error = self::assertApiError(self::apiRequest($this->client, $method, strtr($uri, ['{reviewId}' => $this->reviewId, '{requestId}' => $this->requestId]), $body), 422, 'invalid_input');

        self::assertSame([['field' => $field, 'message' => 'Must not hold a NUL byte.']], $error['details']['violations'] ?? null);
        self::assertSame($events, self::eventCount());
        self::assertSame([], self::listDrafts($this->client, $this->reviewId));
    }

    private static function eventCount(): int
    {
        return (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT count(*) FROM review_event');
    }
}
