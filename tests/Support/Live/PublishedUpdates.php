<?php

declare(strict_types=1);

namespace App\Tests\Support\Live;

use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Live\LiveTopics;
use App\Tests\Support\Contract\SchemaValidator;
use Symfony\Component\Mercure\Update;

/**
 * The updates that the kernel published to the real hub of the test environment, for KernelTestCase tests.
 */
trait PublishedUpdates
{
    /**
     * @return list<Update>
     */
    private static function publishedUpdates(): array
    {
        /** @var list<array{object: Update}> $messages */
        $messages = self::getContainer()->get('mercure.hub.default.traceable')->getMessages();

        return array_column($messages, 'object');
    }

    /**
     * The data of the updates on the repositories topic of the tenant, each private and valid against live-update.schema.json.
     *
     * @return list<array<string, mixed>>
     */
    private static function repositoriesTopicUpdates(TenantId $tenantId): array
    {
        return self::topicUpdates(self::getContainer()->get(LiveTopics::class)->repositories($tenantId));
    }

    /**
     * The data of the updates on the topic of the review, each private and valid against live-update.schema.json.
     *
     * @return list<array<string, mixed>>
     */
    private static function reviewTopicUpdates(TenantId $tenantId, ReviewId $reviewId): array
    {
        return self::topicUpdates(self::getContainer()->get(LiveTopics::class)->review($tenantId, $reviewId));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function topicUpdates(string $topic): array
    {
        $updates = array_values(array_filter(self::publishedUpdates(), static fn (Update $update): bool => [$topic] === $update->getTopics()));

        foreach ($updates as $update) {
            self::assertTrue($update->isPrivate());
            self::assertSame([], SchemaValidator::errors($update->getData(), 'live-update.schema.json'));
        }

        return array_map(self::updateData(...), $updates);
    }

    /**
     * @return array<string, mixed>
     */
    private static function updateData(Update $update): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($update->getData(), true, flags: \JSON_THROW_ON_ERROR);

        return $data;
    }
}
