<?php

declare(strict_types=1);

namespace App\Tests\Support\Persistence;

use App\Domain\Event\Codec\PayloadJson;
use App\Domain\Event\RecordedEvent;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RefName;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Domain\Repository\RepositoryName;
use App\Domain\Request\CommitLinkState;
use App\Domain\Request\RequestState;
use App\Entity\ChangeRequest;
use App\Entity\CommitLink;
use App\Entity\Repository;
use App\Entity\Review;
use App\Projection\RequestStateMapper;
use App\Tenancy\TenantContext;
use App\Tests\Support\Domain\RequestScenario;
use App\Tests\Support\Fixtures\TenantFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

/**
 * Rows of the review data tables for KernelTestCase tests that use ResetDatabase.
 */
trait ReviewDataFixtures
{
    private const string TIME = '2026-09-29T07:00:00Z';
    private const string REVIEW_COMMIT = '79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091';

    private static function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private static function enterNewTenant(): TenantId
    {
        $tenantId = TenantFixtures::of(self::getContainer())->create()->getTenantId();
        self::getContainer()->get(TenantContext::class)->set($tenantId);

        return $tenantId;
    }

    /**
     * Persists a local repository and an open review of it. Does not flush.
     */
    private static function newReview(TenantId $tenantId, ?ReviewId $id = null): Review
    {
        $time = new \DateTimeImmutable(self::TIME);
        $repository = Repository::local($tenantId, RepositoryName::local('billing'), 'billing', $time);
        $review = new Review($id ?? ReviewId::generate(), $repository, 'main @ 79f8595a4c1e', RefName::fromString('main'), CommitSha::fromString(self::REVIEW_COMMIT), RequestScenario::user(), $time, 1);

        self::entityManager()->persist($repository);
        self::entityManager()->persist($review);

        return $review;
    }

    /**
     * Persists the review, the request, and its links in the state of the scenario, then flushes and clears.
     * The links go in last first, so a reader that does not sort gets them out of link order.
     */
    private static function persistScenario(TenantId $tenantId, RequestScenario $scenario): RequestId
    {
        $state = $scenario->state();
        $request = RequestStateMapper::toEntity(self::newReview($tenantId, $scenario->review()->id), $state);
        self::entityManager()->persist($request);

        foreach (array_reverse(self::commitLinks($request, $state)) as $link) {
            self::entityManager()->persist($link);
        }

        self::entityManager()->flush();
        self::entityManager()->clear();

        return $state->id;
    }

    /**
     * @return list<CommitLink>
     */
    private static function commitLinks(ChangeRequest $request, RequestState $state): array
    {
        $links = [];

        foreach ($state->links as $index => $linked) {
            $linkedAt = $state->createdAt->modify("+{$index} seconds");
            $link = new CommitLink($request, $linked->commitSha, RequestScenario::claimingAgent(), $linkedAt, null);

            if (CommitLinkState::Available === $linked->state) {
                $commit = RequestScenario::commit($linked->commitSha->toString());
                $link->found($linkedAt, $commit->subject, $commit->committedAt);
            }

            $links[] = $link;
        }

        return $links;
    }

    /**
     * The event store inserts events with SQL, so tests do too.
     */
    private static function insertEvent(RecordedEvent $event): void
    {
        $actor = $event->actor;

        self::getContainer()->get(Connection::class)->insert('review_event', [
            'id' => self::uuid($event->id),
            'tenant_id' => self::uuid($event->tenantId),
            'seq' => $event->seq,
            'review_id' => self::uuid($event->reviewId),
            'request_id' => null === $event->requestId ? null : self::uuid($event->requestId),
            'request_ids' => json_encode(array_map(static fn (RequestId $id): string => $id->toString(), $event->requestIds()), \JSON_THROW_ON_ERROR),
            'type' => $event->type->value,
            'actor_kind' => $actor->kind->value,
            'actor_user_id' => null === $actor->userId ? null : self::uuid($actor->userId),
            'actor_agent_name' => $actor->agentName,
            'occurred_at' => $event->occurredAt,
            'payload' => PayloadJson::encode($event->event->toPayload()),
        ], ['occurred_at' => Types::DATETIMETZ_IMMUTABLE]);
    }

    private static function uuid(\Stringable $id): string
    {
        return Ulid::fromString((string) $id)->toRfc4122();
    }
}
