<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Draft;
use App\Entity\User;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\DraftApiFixtures;
use App\Tests\Support\Api\RequestApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Live\PublishedUpdates;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SubmitBatchTest extends WebTestCase
{
    use ApiFixtures;
    use DraftApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use PublishedUpdates;
    use QueuedMessages;
    use RequestApiFixtures;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

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

    #[TestDox('Submission writes one batchId on contiguous seqs and deletes the drafts')]
    public function testSubmissionWritesOneBatch(): void
    {
        $requestId = (string) self::openRequest($this->client, $this->reviewId)['id'];
        self::createDraft($this->client, $this->reviewId, ['kind' => 'range', 'path' => self::INVOICE_TOTALS, 'range' => ['startLine' => 5, 'endLine' => 9]], 'Extract the tax rounding.');
        self::createReplyDraft($this->client, $this->reviewId, $requestId);
        $cursor = self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$this->reviewId}/updates"), 200, 'updates-page.schema.json')['cursor'];
        // One container from here on, so the traceable hub of the test records the submission.
        $this->client->disableReboot();
        $updatesBefore = \count(self::publishedUpdates());

        $submission = self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/drafts/submit", ['summary' => 'First pass over billing.']), 201, 'batch-submission.schema.json');
        $updates = \array_slice(self::publishedUpdates(), $updatesBefore);
        $page = self::assertApiResponse(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$this->reviewId}/updates?cursor={$cursor}&includeOwn=true"), 200, 'updates-page.schema.json');

        $batchId = $submission['batchId'];
        [$opened] = $submission['openedRequests'];
        self::assertSame([
            [$cursor + 1, 'request.opened', $batchId, $opened['id']],
            [$cursor + 2, 'request.commented', $batchId, $requestId],
            [$cursor + 3, 'batch.submitted', $batchId, null],
        ], self::eventRows($page));
        self::assertSame([$cursor + 3, 'First pass over billing.', 'Extract the tax rounding.'], [$submission['seq'], $submission['summary'], $opened['summary']]);
        self::assertSame($requestId, $submission['replies'][0]['requestId']);
        self::assertSame([], self::listDrafts($this->client, $this->reviewId));
        self::assertCount(1, $updates, 'The batch arrives in other windows as one live update.');
        self::assertSame($cursor + 3, self::updateData($updates[0])['seq']);
    }

    #[TestDox('A general draft opens a general request')]
    public function testGeneralDraftOpensGeneralRequest(): void
    {
        self::createDraft($this->client, $this->reviewId, null, 'Wrap long lines.');

        $submission = self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/drafts/submit"), 201, 'batch-submission.schema.json');

        self::assertCount(1, $submission['openedRequests']);
        self::assertNull($submission['openedRequests'][0]['anchor']);
    }

    #[TestDox('A draft created during a submission stays')]
    public function testDraftCreatedDuringSubmissionStays(): void
    {
        self::createDraft($this->client, $this->reviewId);
        // One container from here on, so the submission runs the listener of the test.
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        $listener = new class($connection) {
            private bool $inserted = false;

            public function __construct(private readonly Connection $connection)
            {
            }

            /**
             * Runs when the submission has read the drafts, before it deletes them.
             */
            public function postLoad(PostLoadEventArgs $args): void
            {
                $draft = $args->getObject();

                if ($this->inserted || !$draft instanceof Draft) {
                    return;
                }

                $this->inserted = true;
                $this->connection->executeStatement(
                    'INSERT INTO draft (id, tenant_id, kind, anchor, body, created_at, updated_at, user_id, review_id, request_id)'
                    ." SELECT gen_random_uuid(), tenant_id, kind, anchor, 'Written during the submission.', created_at, updated_at, user_id, review_id, request_id FROM draft WHERE id = :id",
                    ['id' => $draft->getId()->toRfc4122()],
                );
            }
        };
        self::getContainer()->get(EntityManagerInterface::class)->getEventManager()->addEventListener(Events::postLoad, $listener);

        $submission = self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/drafts/submit"), 201, 'batch-submission.schema.json');

        self::assertCount(1, $submission['openedRequests']);
        self::assertSame(['Written during the submission.'], array_column(self::listDrafts($this->client, $this->reviewId), 'body'));
    }

    #[TestDox('Submitting zero drafts gives 422 invalid_input')]
    public function testZeroDraftsGiveInvalidInput(): void
    {
        $error = self::assertApiError(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/drafts/submit", ['summary' => null]), 422, 'invalid_input');

        self::assertSame('', $error['details']['violations'][0]['field'] ?? null);
    }

    #[TestDox('Submitting on a closed review gives 409 review_closed')]
    public function testClosedReviewGivesReviewClosed(): void
    {
        self::createDraft($this->client, $this->reviewId);
        // Closing deletes only the drafts of the closing user.
        $this->client->loginUser(self::secondUser($this->user), 'web');
        self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/close"), 200, 'review.schema.json');
        $this->client->loginUser($this->user, 'web');

        self::assertApiError(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/drafts/submit"), 409, 'review_closed');
        self::assertCount(1, self::listDrafts($this->client, $this->reviewId));
    }

    /**
     * @param array<string, mixed> $page An UpdatesPage in cursor mode
     *
     * @return list<array{mixed, mixed, mixed, mixed}> seq, type, batchId, and requestId of each event, by seq
     */
    private static function eventRows(array $page): array
    {
        $rows = [];

        foreach ([...array_merge(...array_column($page['requests'], 'newEvents')), ...$page['reviewEvents']] as $event) {
            $rows[$event['seq']] = [$event['seq'], $event['type'], $event['batchId'], $event['requestId']];
        }

        ksort($rows);

        return array_values($rows);
    }
}
