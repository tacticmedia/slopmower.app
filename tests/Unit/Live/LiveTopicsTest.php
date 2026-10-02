<?php

declare(strict_types=1);

namespace App\Tests\Unit\Live;

use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Live\LiveTopics;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LiveTopicsTest extends KernelTestCase
{
    /** An RFC 6570 simple expansion: unreserved characters and percent-encoded octets, never a slash. */
    private const string SIMPLE_EXPANSION = '(?:[A-Za-z0-9\-._~]|%[0-9A-Fa-f]{2})+';

    #[TestDox("Tenant A's selectors match none of tenant B's topics")]
    public function testSelectorsOfOneTenantMatchNoTopicOfAnother(): void
    {
        $topics = new LiveTopics('https://hr.example/');
        $tenantA = TenantId::generate();
        $tenantB = TenantId::generate();
        $topicsOf = static fn (TenantId $tenantId): array => [
            $topics->repositories($tenantId),
            $topics->review($tenantId, ReviewId::generate()),
            $topics->review($tenantId, ReviewId::generate()),
        ];

        $selectors = $topics->subscriberSelectors($tenantA);

        self::assertSame(["https://hr.example/tenants/{$tenantA}/repositories", "https://hr.example/tenants/{$tenantA}/reviews/{reviewId}"], $selectors);
        self::assertSame([true, true, true], array_map(static fn (string $topic): bool => self::anyMatches($selectors, $topic), $topicsOf($tenantA)));
        self::assertSame([false, false, false], array_map(static fn (string $topic): bool => self::anyMatches($selectors, $topic), $topicsOf($tenantB)));
    }

    /**
     * The selector matching of the hub: equality, or a URI template whose variables are simple expansions.
     *
     * @param list<string> $selectors
     */
    private static function anyMatches(array $selectors, string $topic): bool
    {
        foreach ($selectors as $selector) {
            $pattern = preg_replace('/\\\\\{\w+\\\\}/', self::SIMPLE_EXPANSION, preg_quote($selector, '#'));

            if ($selector === $topic || 1 === preg_match("#^{$pattern}$#", $topic)) {
                return true;
            }
        }

        return false;
    }
}
