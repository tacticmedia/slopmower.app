<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\User;
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
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ViewedFilesTest extends WebTestCase
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

    private const string TAX_ROUNDING = 'src/Billing/TaxRounding.php';

    private KernelBrowser $client;

    private User $user;

    private string $reviewId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource()->commit(self::codeFiles());
        $this->client = static::createClient();
        $this->user = self::signIn($this->client);
        $this->reviewId = self::startCodeReview($this->client);
    }

    #[TestDox('Marks and unmarks a file, idempotently')]
    public function testMarksAndUnmarksIdempotently(): void
    {
        $this->mark(self::TAX_ROUNDING, true);
        $this->mark(self::INVOICE_TOTALS, true);
        self::assertSame([self::INVOICE_TOTALS, self::TAX_ROUNDING], $this->mark(self::INVOICE_TOTALS, true));

        $this->mark(self::INVOICE_TOTALS, false);
        self::assertSame([self::TAX_ROUNDING], $this->mark(self::INVOICE_TOTALS, false));
        self::assertSame([self::TAX_ROUNDING], $this->viewedPaths());
    }

    #[TestDox('A directory path gives 422')]
    public function testDirectoryPathGivesInvalidInput(): void
    {
        foreach (['src/Billing', 'src/Billing/Missing.php'] as $path) {
            $error = self::assertApiError(self::apiRequest($this->client, 'PUT', "/api/v1/reviews/{$this->reviewId}/viewed-files", ['path' => $path, 'viewed' => true]), 422, 'invalid_input');
            self::assertSame('path', $error['details']['violations'][0]['field'] ?? null, $path);
        }

        self::assertSame([], $this->viewedPaths());
    }

    #[TestDox('Marks belong to one user')]
    public function testMarksBelongToOneUser(): void
    {
        $this->mark(self::INVOICE_TOTALS, true);

        $this->client->loginUser(self::secondUser($this->user), 'web');
        self::assertSame([], $this->viewedPaths());
        self::assertSame([self::TAX_ROUNDING], $this->mark(self::TAX_ROUNDING, true));

        $this->client->loginUser($this->user, 'web');
        self::assertSame([self::INVOICE_TOTALS], $this->viewedPaths());
    }

    #[TestDox('A closed review gives 409 review_closed')]
    public function testClosedReviewGivesReviewClosed(): void
    {
        $this->mark(self::INVOICE_TOTALS, true);
        self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/close"), 200, 'review.schema.json');

        self::assertApiError(self::apiRequest($this->client, 'PUT', "/api/v1/reviews/{$this->reviewId}/viewed-files", ['path' => self::TAX_ROUNDING, 'viewed' => true]), 409, 'review_closed');
        self::assertSame([self::INVOICE_TOTALS], $this->viewedPaths());
    }

    /**
     * @return list<string> The paths of the ViewedFiles response
     */
    private function mark(string $path, bool $viewed): array
    {
        return $this->paths(self::apiRequest($this->client, 'PUT', "/api/v1/reviews/{$this->reviewId}/viewed-files", ['path' => $path, 'viewed' => $viewed]));
    }

    /**
     * @return list<string>
     */
    private function viewedPaths(): array
    {
        return $this->paths(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$this->reviewId}/viewed-files"));
    }

    /**
     * @return list<string>
     */
    private function paths(Response $response): array
    {
        $viewedFiles = self::assertApiResponse($response, 200, 'lists.schema.json#/$defs/ViewedFiles');
        self::assertSame($this->reviewId, $viewedFiles['reviewId']);

        return $viewedFiles['paths'];
    }
}
