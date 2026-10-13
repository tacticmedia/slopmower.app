<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Actor\ActorKind;
use App\Domain\Anchor\LineRange;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\ReviewId;
use App\Domain\Location\Location;
use App\Domain\Request\CheckedRequest;
use App\Domain\Request\CheckRunOutput;
use App\Domain\Request\RequestStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The two tables of docs/specs/webhooks-and-live-updates.md, "Check run".
 */
final class CheckRunOutputTest extends KernelTestCase
{
    private const string HEAD = '79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091';

    /**
     * @return iterable<string, array{RequestStatus, ActorKind|null, bool, 'warning'|'notice'|null}>
     */
    public static function requestRows(): iterable
    {
        yield 'open' => [RequestStatus::Open, null, false, 'warning'];
        yield 'reopened' => [RequestStatus::Reopened, null, false, 'warning'];
        yield 'in_progress' => [RequestStatus::InProgress, null, false, 'warning'];
        yield 'ready_for_review' => [RequestStatus::ReadyForReview, null, false, 'notice'];
        yield 'wont_do set by an agent' => [RequestStatus::WontDo, ActorKind::Agent, false, 'notice'];
        yield 'verified' => [RequestStatus::Verified, null, false, null];
        yield 'wont_do set by the user' => [RequestStatus::WontDo, ActorKind::User, false, null];
        yield 'open in a closed review' => [RequestStatus::Open, null, true, null];
        yield 'ready_for_review in a closed review' => [RequestStatus::ReadyForReview, null, true, null];
    }

    /**
     * @param 'warning'|'notice'|null $level
     */
    #[DataProvider('requestRows')]
    #[TestDox('A located request gets the annotation level of its row, and a finished one none')]
    public function testAnnotationLevelOfEachRequestRow(RequestStatus $status, ?ActorKind $wontDoBy, bool $reviewClosed, ?string $level): void
    {
        $output = CheckRunOutput::of([self::request($status, $wontDoBy, $reviewClosed)]);

        self::assertSame(null === $level ? [] : [$level], array_column($output->annotations, 'annotation_level'));
    }

    /**
     * @return iterable<string, array{list<CheckedRequest>, 'in_progress'|'completed', 'success'|'neutral'|null}>
     */
    public static function statusRows(): iterable
    {
        yield 'at least one unfinished' => [[self::request(RequestStatus::Verified), self::request(RequestStatus::ReadyForReview)], 'in_progress', null];
        yield 'all finished' => [[self::request(RequestStatus::Verified), self::request(RequestStatus::WontDo, ActorKind::User)], 'completed', 'success'];
        yield 'none' => [[], 'completed', 'neutral'];
    }

    /**
     * @param list<CheckedRequest>      $requests
     * @param 'in_progress'|'completed' $status
     * @param 'success'|'neutral'|null  $conclusion
     */
    #[DataProvider('statusRows')]
    #[TestDox('The linked requests give the status and conclusion of their row')]
    public function testStatusAndConclusionOfEachRow(array $requests, string $status, ?string $conclusion): void
    {
        $output = CheckRunOutput::of($requests);

        self::assertSame([$status, $conclusion], [$output->status, $output->conclusion]);
    }

    #[TestDox('An annotation holds the headline, the state, and the link of the request, not the rest of its text')]
    public function testAnnotationHoldsNoRequestText(): void
    {
        $output = CheckRunOutput::of([self::request(RequestStatus::Open, body: "\nUse `Money` for totals.\n\nThe customer account 4711 shows the error.")]);

        self::assertSame(
            [['title' => 'Use `Money` for totals.', 'message' => 'Open in Slopmower: http://localhost/requests/1']],
            array_map(static fn (array $annotation): array => array_intersect_key($annotation, ['title' => true, 'message' => true]), $output->annotations),
        );
    }

    private static function request(RequestStatus $status, ?ActorKind $wontDoBy = null, bool $reviewClosed = false, string $body = 'Use `Money` for totals.'): CheckedRequest
    {
        $location = Location::exact(CommitSha::fromString(self::HEAD), RepoPath::fromString('src/Invoice.php'), LineRange::of(3, 4));

        return new CheckedRequest(ReviewId::generate(), 'main @ 79f8595a4c1e', 'http://localhost/reviews/1/requests', $reviewClosed, 'http://localhost/requests/1', $body, $status, $wontDoBy, false, $location);
    }
}
