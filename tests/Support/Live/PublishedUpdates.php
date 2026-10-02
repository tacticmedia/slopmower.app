<?php

declare(strict_types=1);

namespace App\Tests\Support\Live;

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
     * @return array<string, mixed>
     */
    private static function updateData(Update $update): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($update->getData(), true, flags: \JSON_THROW_ON_ERROR);

        return $data;
    }
}
