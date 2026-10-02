<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class PathValidationTest extends WebTestCase
{
    use ApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private KernelBrowser $client;

    private string $reviewId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource()->commit(['src/App.php' => "<?php\n"]);
        $this->client = static::createClient();
        self::signIn($this->client);
        $this->reviewId = (string) self::startReviewThroughApi($this->client, self::connectAndClone($this->client))['id'];
    }

    #[TestDox('../x, an absolute path, and NUL give 422 invalid_input')]
    public function testTraversalAbsoluteAndNulGiveInvalidInput(): void
    {
        foreach (['file', 'raw'] as $endpoint) {
            foreach (['../x', '/src/App.php', "src/App.php\0"] as $path) {
                $error = self::assertApiError($this->get($endpoint, $path), 422, 'invalid_input');
                self::assertSame(['path'], array_column($error['details']['violations'] ?? [], 'field'), "{$endpoint} {$path}");
            }
        }
    }

    #[TestDox('A missing path gives 404 not_found')]
    public function testMissingPathGivesNotFound(): void
    {
        foreach (['file' => 'src/Missing.php', 'raw' => 'missing/App.php'] as $endpoint => $path) {
            self::assertApiError($this->get($endpoint, $path), 404, 'not_found');
        }
    }

    #[TestDox('A directory path on the file endpoint gives 422')]
    public function testDirectoryOnFileEndpointGivesInvalidInput(): void
    {
        $error = self::assertApiError($this->get('file', 'src'), 422, 'invalid_input');

        self::assertSame(['path'], array_column($error['details']['violations'] ?? [], 'field'));
    }

    private function get(string $endpoint, string $path): Response
    {
        return self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$this->reviewId}/{$endpoint}?path=".rawurlencode($path));
    }
}
