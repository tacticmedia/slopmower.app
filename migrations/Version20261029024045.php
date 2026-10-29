<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Ulid;

final class Version20261029024045 extends AbstractMigration
{
    private const array DROPPED_COLUMNS = [
        'anchor', 'body', 'created_at', 'status_before_claim', 'claimed_at', 'wont_do_by', 'resolution', 'last_user_comment_seq',
        'last_agent_event_seq', 'last_agent_event_is_question', 'author_kind', 'author_user_id', 'author_agent_name', 'claimed_by_kind',
        'claimed_by_user_id', 'claimed_by_agent_name', 'carried_from_review_id', 'carried_from_request_id', 'require_approval',
        'proposal_event_id', 'proposal_state', 'pull_request_urls', 'title',
    ];

    public function getDescription(): string
    {
        return 'The request state in one jsonb column (F89, D98)';
    }

    /**
     * Writes the format of RequestState::toPayload() from the old columns. Links of one second keep the SHA order.
     */
    public function up(Schema $schema): void
    {
        $links = [];

        foreach ($this->connection->fetchAllAssociative('SELECT request_id, commit_sha, state FROM commit_link ORDER BY linked_at, commit_sha') as $link) {
            $links[$link['request_id']][] = ['commitSha' => $link['commit_sha'], 'state' => $link['state']];
        }

        $states = [];

        foreach ($this->connection->fetchAllAssociative('SELECT * FROM change_request') as $row) {
            $states[$row['id']] = json_encode(self::payload($row, $links[$row['id']] ?? []), \JSON_THROW_ON_ERROR);
        }

        $this->addSql('ALTER TABLE change_request ADD state JSONB DEFAULT NULL');

        foreach ($states as $id => $state) {
            $this->addSql('UPDATE change_request SET state = :state WHERE id = :id', ['state' => $state, 'id' => $id]);
        }

        $this->addSql('ALTER TABLE change_request ALTER state SET NOT NULL');

        foreach (self::DROPPED_COLUMNS as $column) {
            $this->addSql("ALTER TABLE change_request DROP {$column}");
        }
    }

    /**
     * @param array<string, mixed>                          $row   Of the old change_request
     * @param list<array{commitSha: string, state: string}> $links
     *
     * @return array<string, mixed>
     */
    public static function payload(array $row, array $links): array
    {
        return [
            'anchor' => null === $row['anchor'] ? null : json_decode($row['anchor'], true, flags: \JSON_THROW_ON_ERROR),
            'title' => $row['title'],
            'body' => $row['body'],
            'author' => self::actor($row['author_kind'], $row['author_user_id'], $row['author_agent_name']),
            'createdAt' => self::time($row['created_at']),
            'status' => $row['status'],
            'statusBeforeClaim' => $row['status_before_claim'],
            'claimedBy' => null === $row['claimed_by_kind'] ? null : self::actor($row['claimed_by_kind'], $row['claimed_by_user_id'], $row['claimed_by_agent_name']),
            'claimedAt' => null === $row['claimed_at'] ? null : self::time($row['claimed_at']),
            'wontDoBy' => $row['wont_do_by'],
            'resolution' => $row['resolution'],
            'links' => $links,
            'pullRequestUrls' => json_decode($row['pull_request_urls'], true, flags: \JSON_THROW_ON_ERROR),
            'lastUserCommentSeq' => null === $row['last_user_comment_seq'] ? null : (int) $row['last_user_comment_seq'],
            'lastAgentEventSeq' => null === $row['last_agent_event_seq'] ? null : (int) $row['last_agent_event_seq'],
            'lastAgentEventIsQuestion' => (bool) $row['last_agent_event_is_question'],
            'lastEventSeq' => (int) $row['last_event_seq'],
            'updatedAt' => self::time($row['updated_at']),
            'carriedFrom' => null === $row['carried_from_review_id'] || null === $row['carried_from_request_id'] ? null : [
                'reviewId' => self::base32($row['carried_from_review_id']),
                'requestId' => self::base32($row['carried_from_request_id']),
            ],
            'requireApproval' => (bool) $row['require_approval'],
            'proposal' => null === $row['proposal_event_id'] || null === $row['proposal_state'] ? null : [
                'eventId' => self::base32($row['proposal_event_id']),
                'state' => $row['proposal_state'],
            ],
            'suggestion' => $row['suggestion_state'],
        ];
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The old columns cannot be filled back. Restore a backup taken before this migration.');
    }

    /**
     * @return array{kind: string, userId: string|null, agentName: string|null}
     */
    private static function actor(string $kind, ?string $userId, ?string $agentName): array
    {
        return ['kind' => $kind, 'userId' => null === $userId ? null : self::base32($userId), 'agentName' => $agentName];
    }

    private static function base32(string $rfc4122): string
    {
        return Ulid::fromString($rfc4122)->toBase32();
    }

    private static function time(string $utcWallTime): string
    {
        return new \DateTimeImmutable($utcWallTime, new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
