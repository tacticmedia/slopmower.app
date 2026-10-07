<?php

declare(strict_types=1);

namespace Mate;

use App\Domain\Error\InvalidInput;
use App\Domain\Event\Codec\TimeCodec;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Review\ReviewState;
use Doctrine\DBAL\Connection;
use Symfony\AI\Mate\Attribute\MateTool;
use Symfony\AI\Mate\Encoding\ResponseEncoder;
use Symfony\Component\Uid\Ulid;

/**
 * Reads the dev database across all tenants: no tenant filter.
 */
final readonly class ReviewTools
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param string|null      $repository Case-insensitive substring of the repository name
     * @param ReviewState|null $state      Only the reviews in this state
     * @param int              $limit      Maximum number of reviews, 1 to 100
     */
    #[MateTool(name: 'review-list', title: 'Review List', description: 'List the reviews of the dev database, newest first, with their repository and number of requests. IDs are upper-case ULIDs, as in the API.')]
    public function list(?string $repository = null, ?ReviewState $state = null, int $limit = 20): string
    {
        $query = $this->connection->createQueryBuilder()
            ->select('review.id', 'review.tenant_id', 'repository.name', 'review.title', 'review.state', 'review.commit_sha', 'review.last_event_seq', 'review.created_at')
            ->addSelect('(SELECT COUNT(*) FROM change_request WHERE change_request.review_id = review.id) AS request_count')
            ->from('review')
            ->innerJoin('review', 'repository', 'repository', 'repository.id = review.repository_id')
            ->orderBy('review.created_at', 'DESC')
            ->addOrderBy('review.id', 'DESC')
            ->setMaxResults(self::limit($limit, 100));

        if (null !== $repository) {
            $query->andWhere('STRPOS(LOWER(repository.name), LOWER(:repository)) > 0')->setParameter('repository', $repository);
        }

        if (null !== $state) {
            $query->andWhere('review.state = :state')->setParameter('state', $state->value);
        }

        $reviews = array_map(static fn (array $row): array => [
            'id' => self::ulid($row['id']),
            'tenantId' => self::ulid($row['tenant_id']),
            'repository' => $row['name'],
            'title' => $row['title'],
            'state' => $row['state'],
            'commitSha' => $row['commit_sha'],
            'lastEventSeq' => (int) $row['last_event_seq'],
            'createdAt' => self::time($row['created_at']),
            'requestCount' => (int) $row['request_count'],
        ], $query->fetchAllAssociative());

        return ResponseEncoder::encodeUntrusted(['reviews' => $reviews]);
    }

    /**
     * @param string      $review   Review ULID
     * @param string|null $request  Request ULID: only the events whose requestIds contain it
     * @param int|null    $afterSeq Only the events after this seq, to read the next page
     * @param string|null $type     Event type prefix, for example "request." or "request.opened"
     * @param int         $limit    Maximum number of events, 1 to 500
     */
    #[MateTool(name: 'review-events', title: 'Review Events', description: 'Read the event log of one review of the dev database in seq order: type, time, actor, request IDs, and the decoded payload. IDs are upper-case ULIDs, as in the API.')]
    public function events(string $review, ?string $request = null, ?int $afterSeq = null, ?string $type = null, int $limit = 50): string
    {
        $query = $this->connection->createQueryBuilder()
            ->select('seq', 'type', 'occurred_at', 'actor_kind', 'actor_user_id', 'actor_agent_name', 'request_id', 'request_ids', 'payload')
            ->from('review_event')
            ->where('review_id = :review')
            ->setParameter('review', ReviewId::fromString($review, 'review')->toRfc4122())
            ->orderBy('seq')
            ->setMaxResults(self::limit($limit, 500));

        if (null !== $request) {
            $query->andWhere('request_ids @> :requestIds')->setParameter('requestIds', json_encode([RequestId::fromString($request, 'request')->toString()], \JSON_THROW_ON_ERROR));
        }

        if (null !== $afterSeq) {
            $query->andWhere('seq > :afterSeq')->setParameter('afterSeq', $afterSeq);
        }

        if (null !== $type) {
            $query->andWhere('STARTS_WITH(type, :type)')->setParameter('type', $type);
        }

        $events = array_map(static fn (array $row): array => [
            'seq' => (int) $row['seq'],
            'type' => $row['type'],
            'occurredAt' => self::time($row['occurred_at']),
            'actor' => [
                'kind' => $row['actor_kind'],
                'userId' => null === $row['actor_user_id'] ? null : self::ulid($row['actor_user_id']),
                'agentName' => $row['actor_agent_name'],
            ],
            'requestId' => null === $row['request_id'] ? null : self::ulid($row['request_id']),
            'requestIds' => self::json($row['request_ids']),
            'payload' => self::json($row['payload']),
        ], $query->fetchAllAssociative());

        return ResponseEncoder::encodeUntrusted(['events' => $events]);
    }

    private static function limit(int $limit, int $max): int
    {
        return $limit >= 1 && $limit <= $max ? $limit : throw InvalidInput::field('limit', "Must be from 1 to {$max}.");
    }

    private static function ulid(mixed $uuid): string
    {
        return Ulid::fromString((string) $uuid)->toBase32();
    }

    private static function time(mixed $timestamp): string
    {
        return TimeCodec::encode(new \DateTimeImmutable((string) $timestamp));
    }

    private static function json(mixed $json): mixed
    {
        return json_decode((string) $json, true, flags: \JSON_THROW_ON_ERROR);
    }
}
