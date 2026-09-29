<?php

declare(strict_types=1);

namespace App\Tests\Support\Fixtures;

use App\Domain\Actor\Actor;
use App\Domain\Anchor\Anchor;
use App\Domain\Event\DomainEvent;
use App\Domain\Event\RecordedEvent;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RefName;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\EventId;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Domain\Repository\RepositoryName;
use App\Entity\ChangeRequest;
use App\Entity\Repository;
use App\Entity\Review;

/**
 * Unsaved review data with whole-second times, because the database stores no fraction of a second.
 */
final class ReviewData
{
    public const string REVIEW_COMMIT = '79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091';
    public const string FIX_COMMIT = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
    public const string OTHER_COMMIT = 'e5f6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e';
    public const string BLOB = '3b18e512dba79e4c8300dd08aeb37f8e728b8dad';

    public static function time(string $time = '2026-09-29T07:30:00Z'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time);
    }

    public static function gitHubRepository(TenantId $tenantId, string $name = 'acme/billing', int $githubRepositoryId = 123456789012): Repository
    {
        return Repository::gitHub(RepositoryId::generate(), $tenantId, RepositoryName::gitHub($name), $githubRepositoryId, self::time());
    }

    public static function localRepository(TenantId $tenantId, string $name = 'billing'): Repository
    {
        return Repository::local(RepositoryId::generate(), $tenantId, RepositoryName::local($name), $name, self::time());
    }

    public static function review(Repository $repository, Actor $startedBy, ?ReviewId $id = null): Review
    {
        return new Review(
            $id ?? ReviewId::generate(),
            $repository,
            'main @ 79f8595a4c1e',
            RefName::fromString('main'),
            CommitSha::fromString(self::REVIEW_COMMIT),
            $startedBy,
            self::time(),
            1,
        );
    }

    public static function request(Review $review, Actor $author, ?RequestId $id = null, ?Anchor $anchor = null): ChangeRequest
    {
        return new ChangeRequest($id ?? RequestId::generate(), $review, $anchor ?? self::fileAnchor(), 'Use `Money` for totals.', $author, self::time('2026-09-29T07:31:00Z'), 2);
    }

    public static function fileAnchor(): Anchor
    {
        return Anchor::file(RepoPath::fromString('src/Billing/InvoiceTotals.php'), CommitSha::fromString(self::REVIEW_COMMIT), BlobSha::fromString(self::BLOB));
    }

    public static function recordedEvent(TenantId $tenantId, ReviewId $reviewId, int $seq, DomainEvent $event, Actor $actor, ?RequestId $requestId = null): RecordedEvent
    {
        return new RecordedEvent(
            EventId::generate(),
            $tenantId,
            $seq,
            $reviewId,
            $event::type()->hasRequestId() ? $requestId ?? RequestId::generate() : null,
            $event::type(),
            $actor,
            self::time('2026-09-29T08:00:00Z')->modify(\sprintf('+%d seconds', $seq)),
            $event,
        );
    }
}
