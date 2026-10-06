<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Domain\Anchor\Anchor;
use App\Domain\Anchor\AnchorKind;
use App\Domain\Git\BlobSha;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\ReviewId;
use App\Entity\Draft;
use App\Entity\Review;
use App\Entity\User;
use App\Tenancy\TenantFilter;
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
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Ulid;

final class DraftRulesTest extends WebTestCase
{
    use ApiFixtures;
    use ClockSensitiveTrait;
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

    #[TestDox('The anchor is captured at creation')]
    public function testAnchorIsCapturedAtCreation(): void
    {
        $anchor = ['kind' => 'range', 'path' => self::INVOICE_TOTALS, 'range' => ['startLine' => 5, 'endLine' => 9]];

        $draft = self::createDraft($this->client, $this->reviewId, $anchor + ['snapshot' => ['lines' => ['Not from the client.']]]);

        self::assertSame(['new_request', null], [$draft['kind'], $draft['requestId']]);
        self::assertSame(self::openRequest($this->client, $this->reviewId, $anchor)['anchor'], $draft['anchor'], 'The same capture as Q2.');
        self::assertSame('    $total += 5;', $draft['anchor']['snapshot']['lines'][0]);
    }

    #[TestDox('A 501st draft gives 422')]
    public function testFiveHundredFirstDraftGivesInvalidInput(): void
    {
        $this->storeDrafts(Draft::MAX_PER_USER_AND_REVIEW);

        $error = self::assertApiError(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/drafts", ['kind' => 'new_request', 'anchor' => ['kind' => 'file', 'path' => 'README.md'], 'body' => 'One too many.']), 422, 'invalid_input');

        self::assertSame('', $error['details']['violations'][0]['field'] ?? null);
        self::assertCount(Draft::MAX_PER_USER_AND_REVIEW, self::listDrafts($this->client, $this->reviewId));
    }

    #[TestDox('A reply to a request of another review gives 422')]
    public function testReplyToRequestOfAnotherReviewGivesInvalidInput(): void
    {
        $otherReviewId = (string) self::startReviewThroughApi($this->client, (string) self::readReview($this->client, $this->reviewId)['repositoryId'])['id'];
        $otherRequestId = (string) self::openRequest($this->client, $otherReviewId)['id'];

        $error = self::assertApiError(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/drafts", ['kind' => 'reply', 'requestId' => $otherRequestId, 'body' => 'Wrong review.']), 422, 'invalid_input');

        self::assertSame('requestId', $error['details']['violations'][0]['field'] ?? null);
        self::assertApiError(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/drafts", ['kind' => 'reply', 'requestId' => (string) new Ulid(), 'body' => 'Unknown request.']), 404, 'not_found');
    }

    #[TestDox('PATCH changes the body only')]
    public function testPatchChangesTheBodyOnly(): void
    {
        self::mockTime('2026-09-29T07:12:00Z');
        $draft = self::createDraft($this->client, $this->reviewId, ['kind' => 'line', 'path' => self::INVOICE_TOTALS, 'range' => ['startLine' => 3, 'endLine' => 3]]);
        self::mockTime('2026-09-29T07:14:30Z');

        $patched = self::assertApiResponse(self::apiRequest($this->client, 'PATCH', "/api/v1/drafts/{$draft['id']}", [
            'body' => 'Use `Money` for totals and taxes.',
            'kind' => 'reply',
            'anchor' => ['kind' => 'file', 'path' => 'README.md'],
        ]), 200, 'draft.schema.json');

        self::assertSame(array_replace($draft, ['body' => 'Use `Money` for totals and taxes.', 'updatedAt' => '2026-09-29T07:14:30Z']), $patched);
        self::assertSame([$patched], self::listDrafts($this->client, $this->reviewId));
    }

    #[TestDox("Another user's PATCH and DELETE of a draft give 404 not_found")]
    public function testOtherUsersDraftIsNotFound(): void
    {
        $draft = self::createDraft($this->client, $this->reviewId);
        $this->client->loginUser(self::secondUser($this->user), 'web');

        self::assertApiError(self::apiRequest($this->client, 'PATCH', "/api/v1/drafts/{$draft['id']}", ['body' => 'Not mine.']), 404, 'not_found');
        self::assertApiError(self::apiRequest($this->client, 'DELETE', "/api/v1/drafts/{$draft['id']}"), 404, 'not_found');

        $this->client->loginUser($this->user, 'web');
        self::assertSame([$draft], self::listDrafts($this->client, $this->reviewId));
    }

    #[TestDox('List and delete work on a closed review, and create and update give review_closed')]
    public function testClosedReviewAllowsListAndDeleteOnly(): void
    {
        $kept = self::createDraft($this->client, $this->reviewId, body: 'Kept.');
        $deleted = self::createDraft($this->client, $this->reviewId, body: 'Deleted.');
        // Closing deletes only the drafts of the closing user.
        $this->client->loginUser(self::secondUser($this->user), 'web');
        self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/close"), 200, 'review.schema.json');
        $this->client->loginUser($this->user, 'web');

        self::assertCount(2, self::listDrafts($this->client, $this->reviewId));
        self::assertApiError(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/drafts", ['kind' => 'new_request', 'anchor' => ['kind' => 'file', 'path' => 'README.md'], 'body' => 'Too late.']), 409, 'review_closed');
        self::assertApiError(self::apiRequest($this->client, 'PATCH', "/api/v1/drafts/{$kept['id']}", ['body' => 'Too late.']), 409, 'review_closed');
        self::apiRequest($this->client, 'DELETE', "/api/v1/drafts/{$deleted['id']}");
        self::assertResponseStatusCodeSame(204);
        self::assertSame([$kept], self::listDrafts($this->client, $this->reviewId));
    }

    /**
     * File drafts of the signed-in user, stored without the API.
     */
    private function storeDrafts(int $count): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        [$user, $review] = self::getContainer()->get(TenantFilter::class)->suspend(fn (): array => [
            $entityManager->find(User::class, $this->user->getId()),
            $entityManager->find(Review::class, ReviewId::fromString($this->reviewId)),
        ]);
        \assert($user instanceof User && $review instanceof Review);
        $anchor = Anchor::of(AnchorKind::File, RepoPath::fromString('README.md'), $review->getCommitSha(), BlobSha::fromString('e965047ad7c57865823c7d992b1d046ea66edf78'), null, null, null);

        for ($i = 0; $i < $count; ++$i) {
            $entityManager->persist(Draft::newRequest($user, $review, $anchor, "Draft {$i}.", Clock::get()->now()));
        }

        $entityManager->flush();
        $entityManager->clear();
    }

    /**
     * @return array<string, mixed> The Review
     */
    private static function readReview(KernelBrowser $client, string $reviewId): array
    {
        return self::assertApiResponse(self::apiRequest($client, 'GET', "/api/v1/reviews/{$reviewId}"), 200, 'review.schema.json');
    }
}
