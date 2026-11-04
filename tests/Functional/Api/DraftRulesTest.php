<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Domain\Anchor\Anchor;
use App\Domain\Anchor\AnchorKind;
use App\Domain\Git\BlobSha;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\ReviewId;
use App\Domain\Request\RequestType;
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
        $opened = self::openRequest($this->client, $this->reviewId, $anchor)['anchor'];
        $opened['snapshot'] = array_diff_key($opened['snapshot'], array_flip(['lines', 'before', 'after']));

        self::assertEquals($opened, $draft['anchor'], 'The same capture as Q2, whose answer rebuilds the code text.');
        self::assertArrayNotHasKey('lines', $draft['anchor']['snapshot'], 'A draft stores no code text (D113).');
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
        ]), 200, 'draft.schema.json');

        self::assertSame(array_replace($draft, ['body' => 'Use `Money` for totals and taxes.', 'updatedAt' => '2026-09-29T07:14:30Z']), $patched);
        self::assertSame([$patched], self::listDrafts($this->client, $this->reviewId));
    }

    #[TestDox('D2 and D3 store requireApproval of a new request, and a PATCH without it keeps it')]
    public function testDraftStoresRequireApproval(): void
    {
        $draft = self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/drafts", ['kind' => 'new_request', 'body' => 'Add a CSV export.', 'requireApproval' => true]), 201, 'draft.schema.json');
        self::assertTrue($draft['requireApproval']);

        $kept = self::assertApiResponse(self::apiRequest($this->client, 'PATCH', "/api/v1/drafts/{$draft['id']}", ['body' => 'Add a CSV export of the totals.']), 200, 'draft.schema.json');
        $changed = self::assertApiResponse(self::apiRequest($this->client, 'PATCH', "/api/v1/drafts/{$draft['id']}", ['body' => 'Add a CSV export of the totals.', 'requireApproval' => false]), 200, 'draft.schema.json');

        self::assertSame([true, false], [$kept['requireApproval'], $changed['requireApproval']]);
        self::assertSame([$changed], self::listDrafts($this->client, $this->reviewId));
    }

    #[TestDox('requireApproval on a reply draft gives 422 in D2 and D3')]
    public function testReplyDraftRefusesRequireApproval(): void
    {
        $requestId = (string) self::openRequest($this->client, $this->reviewId)['id'];
        $reply = self::createReplyDraft($this->client, $this->reviewId, $requestId);
        self::assertFalse($reply['requireApproval']);

        $created = self::assertApiError(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/drafts", ['kind' => 'reply', 'requestId' => $requestId, 'body' => 'Plan first.', 'requireApproval' => true]), 422, 'invalid_input');
        $updated = self::assertApiError(self::apiRequest($this->client, 'PATCH', "/api/v1/drafts/{$reply['id']}", ['body' => 'Plan first.', 'requireApproval' => true]), 422, 'invalid_input');

        self::assertSame([['requireApproval'], ['requireApproval']], [array_column($created['details']['violations'] ?? [], 'field'), array_column($updated['details']['violations'] ?? [], 'field')]);
        self::assertSame([$reply], self::listDrafts($this->client, $this->reviewId));
    }

    #[TestDox('D2 stores the type of a new request, and change without one')]
    public function testDraftStoresTheType(): void
    {
        $question = self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/drafts", ['kind' => 'new_request', 'body' => 'Why float?', 'type' => 'question']), 201, 'draft.schema.json');
        $change = self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/drafts", ['kind' => 'new_request', 'body' => 'Add a CSV export.']), 201, 'draft.schema.json');

        self::assertSame(['question', 'change'], [$question['type'], $change['type']]);
    }

    #[TestDox('D3 changes the type of a new request, and a PATCH without it keeps it')]
    public function testPatchChangesTheType(): void
    {
        $draft = self::createDraft($this->client, $this->reviewId);

        $changed = self::assertApiResponse(self::apiRequest($this->client, 'PATCH', "/api/v1/drafts/{$draft['id']}", ['body' => 'Never use float for money.', 'type' => 'rule']), 200, 'draft.schema.json');
        $kept = self::assertApiResponse(self::apiRequest($this->client, 'PATCH', "/api/v1/drafts/{$draft['id']}", ['body' => 'Never use float for money.', 'type' => null]), 200, 'draft.schema.json');

        self::assertSame(['change', 'rule', 'rule'], [$draft['type'], $changed['type'], $kept['type']]);
        self::assertSame([$kept], self::listDrafts($this->client, $this->reviewId));
    }

    #[TestDox('A type on a reply draft gives 422 in D2 and D3')]
    public function testReplyDraftRefusesType(): void
    {
        $requestId = (string) self::openRequest($this->client, $this->reviewId)['id'];
        $reply = self::createReplyDraft($this->client, $this->reviewId, $requestId);
        self::assertSame('change', $reply['type']);

        $created = self::assertApiError(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/drafts", ['kind' => 'reply', 'requestId' => $requestId, 'body' => 'Why float?', 'type' => 'question']), 422, 'invalid_input');
        $updated = self::assertApiError(self::apiRequest($this->client, 'PATCH', "/api/v1/drafts/{$reply['id']}", ['body' => 'Why float?', 'type' => 'question']), 422, 'invalid_input');

        self::assertSame([['type'], ['type']], [array_column($created['details']['violations'] ?? [], 'field'), array_column($updated['details']['violations'] ?? [], 'field')]);
        self::assertSame([$reply], self::listDrafts($this->client, $this->reviewId));
    }

    #[TestDox('D2 stores the group of a new request trimmed, and null without one')]
    public function testDraftStoresTheGroup(): void
    {
        $grouped = self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/drafts", ['kind' => 'new_request', 'body' => 'Use Money here.', 'group' => ' Money ']), 201, 'draft.schema.json');
        $single = self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/drafts", ['kind' => 'new_request', 'body' => 'Add a CSV export.']), 201, 'draft.schema.json');

        self::assertSame(['Money', null], [$grouped['group'], $single['group']]);
    }

    #[TestDox('D3 replaces the group of a new request, and a PATCH without it removes it')]
    public function testPatchReplacesTheGroup(): void
    {
        $draft = self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/drafts", ['kind' => 'new_request', 'body' => 'Use Money here.', 'group' => 'Money']), 201, 'draft.schema.json');

        $moved = self::assertApiResponse(self::apiRequest($this->client, 'PATCH', "/api/v1/drafts/{$draft['id']}", ['body' => 'Use Money here.', 'group' => 'Totals']), 200, 'draft.schema.json');
        $removed = self::assertApiResponse(self::apiRequest($this->client, 'PATCH', "/api/v1/drafts/{$draft['id']}", ['body' => 'Use Money here.']), 200, 'draft.schema.json');

        self::assertSame(['Totals', null], [$moved['group'], $removed['group']]);
        self::assertSame([$removed], self::listDrafts($this->client, $this->reviewId));
    }

    #[TestDox('A blank group, a group with a line break, or a group on a reply draft gives 422')]
    public function testInvalidGroupGivesInvalidInput(): void
    {
        $requestId = (string) self::openRequest($this->client, $this->reviewId)['id'];
        $reply = self::createReplyDraft($this->client, $this->reviewId, $requestId);

        $blank = self::assertApiError(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/drafts", ['kind' => 'new_request', 'body' => 'Use Money here.', 'group' => '  ']), 422, 'invalid_input');
        $twoLines = self::assertApiError(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/drafts", ['kind' => 'new_request', 'body' => 'Use Money here.', 'group' => "Money\nTotals"]), 422, 'invalid_input');
        $onReply = self::assertApiError(self::apiRequest($this->client, 'PATCH', "/api/v1/drafts/{$reply['id']}", ['body' => 'Plan first.', 'group' => 'Money']), 422, 'invalid_input');

        self::assertSame([['group'], ['group'], ['group']], array_map(static fn (array $error): array => array_column($error['details']['violations'] ?? [], 'field'), [$blank, $twoLines, $onReply]));
        self::assertSame([$reply], self::listDrafts($this->client, $this->reviewId));
    }

    #[TestDox('D2 stores the title of a new request, and D3 replaces title and body together')]
    public function testDraftStoresAndReplacesTheTitle(): void
    {
        $draft = self::assertApiResponse(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/drafts", ['kind' => 'new_request', 'title' => 'CSV export', 'body' => 'Add a CSV export.']), 201, 'draft.schema.json');

        $retitled = self::assertApiResponse(self::apiRequest($this->client, 'PATCH', "/api/v1/drafts/{$draft['id']}", ['title' => 'Totals export', 'body' => 'Add a CSV export.']), 200, 'draft.schema.json');
        $untitled = self::assertApiResponse(self::apiRequest($this->client, 'PATCH', "/api/v1/drafts/{$draft['id']}", ['body' => 'Add a CSV export.']), 200, 'draft.schema.json');

        self::assertSame(['CSV export', 'Totals export', null], [$draft['title'], $retitled['title'], $untitled['title']]);
    }

    #[TestDox('A title on a reply draft gives 422 in D2 and D3')]
    public function testReplyDraftRefusesTitle(): void
    {
        $requestId = (string) self::openRequest($this->client, $this->reviewId)['id'];
        $reply = self::createReplyDraft($this->client, $this->reviewId, $requestId);

        $created = self::assertApiError(self::apiRequest($this->client, 'POST', "/api/v1/reviews/{$this->reviewId}/drafts", ['kind' => 'reply', 'requestId' => $requestId, 'title' => 'Totals', 'body' => 'Plan first.']), 422, 'invalid_input');
        $updated = self::assertApiError(self::apiRequest($this->client, 'PATCH', "/api/v1/drafts/{$reply['id']}", ['title' => 'Totals', 'body' => 'Plan first.']), 422, 'invalid_input');

        self::assertSame([['title'], ['title']], [array_column($created['details']['violations'] ?? [], 'field'), array_column($updated['details']['violations'] ?? [], 'field')]);
        self::assertSame([$reply], self::listDrafts($this->client, $this->reviewId));
    }

    #[TestDox('D3 with an anchor moves a new request draft, captured as in D2, and keeps its ID and createdAt')]
    public function testPatchMovesTheAnchor(): void
    {
        $draft = self::createDraft($this->client, $this->reviewId, ['kind' => 'line', 'path' => self::INVOICE_TOTALS, 'range' => ['startLine' => 3, 'endLine' => 3]]);
        $anchor = ['kind' => 'range', 'path' => self::INVOICE_TOTALS, 'range' => ['startLine' => 5, 'endLine' => 9]];

        $moved = self::assertApiResponse(self::apiRequest($this->client, 'PATCH', "/api/v1/drafts/{$draft['id']}", ['body' => 'Extract the tax calculation.', 'anchor' => $anchor]), 200, 'draft.schema.json');

        self::assertEquals(self::createDraft($this->client, $this->reviewId, $anchor)['anchor'], $moved['anchor']);
        self::assertSame([$draft['id'], $draft['createdAt']], [$moved['id'], $moved['createdAt']]);
    }

    #[TestDox('An anchor on a reply draft gives 422 in D3')]
    public function testReplyDraftRefusesAnchor(): void
    {
        $reply = self::createReplyDraft($this->client, $this->reviewId, (string) self::openRequest($this->client, $this->reviewId)['id']);

        $updated = self::assertApiError(self::apiRequest($this->client, 'PATCH', "/api/v1/drafts/{$reply['id']}", ['body' => 'Plan first.', 'anchor' => ['kind' => 'file', 'path' => 'README.md']]), 422, 'invalid_input');

        self::assertSame(['anchor'], array_column($updated['details']['violations'] ?? [], 'field'));
        self::assertSame([$reply], self::listDrafts($this->client, $this->reviewId));
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
            $entityManager->persist(Draft::newRequest($user, $review, $anchor, null, "Draft {$i}.", false, RequestType::Change, null, Clock::get()->now()));
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
