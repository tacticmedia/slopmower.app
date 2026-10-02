<?php

declare(strict_types=1);

namespace App\Tests\Support\Api;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\Clock;

/**
 * Drafts through the API, for WebTestCase tests that use RequestApiFixtures and its traits.
 */
trait DraftApiFixtures
{
    /**
     * @param array<string, mixed> $anchor
     *
     * @return array<string, mixed> The Draft
     */
    private static function createDraft(KernelBrowser $client, string $reviewId, array $anchor = ['kind' => 'file', 'path' => 'README.md'], string $body = 'Use `Money` instead of `float` for totals.'): array
    {
        return self::assertApiResponse(self::apiRequest($client, 'POST', "/api/v1/reviews/{$reviewId}/drafts", ['kind' => 'new_request', 'anchor' => $anchor, 'body' => $body]), 201, 'draft.schema.json');
    }

    /**
     * @return array<string, mixed> The Draft
     */
    private static function createReplyDraft(KernelBrowser $client, string $reviewId, string $requestId, string $body = 'Keep the rounding mode configurable.'): array
    {
        return self::assertApiResponse(self::apiRequest($client, 'POST', "/api/v1/reviews/{$reviewId}/drafts", ['kind' => 'reply', 'requestId' => $requestId, 'body' => $body]), 201, 'draft.schema.json');
    }

    /**
     * @return list<array<string, mixed>> The items of the DraftList
     */
    private static function listDrafts(KernelBrowser $client, string $reviewId): array
    {
        $list = self::assertApiResponse(self::apiRequest($client, 'GET', "/api/v1/reviews/{$reviewId}/drafts"), 200, 'lists.schema.json#/$defs/DraftList');
        self::assertSame($reviewId, $list['reviewId']);

        return $list['items'];
    }

    /**
     * A second local user of the tenant of $user. Drafts and viewed files are private to each user.
     */
    private static function secondUser(User $user, string $login = 'ana'): User
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $second = User::local($user->getTenantId(), $login, Clock::get()->now());
        $entityManager->persist($second);
        $entityManager->flush();
        $entityManager->clear();

        return $second;
    }
}
