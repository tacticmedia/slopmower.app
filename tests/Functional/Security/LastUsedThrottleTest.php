<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class LastUsedThrottleTest extends WebTestCase
{
    use ApiFixtures;
    use ClockSensitiveTrait;
    use ResetDatabase;

    #[TestDox('lastUsedAt changes at most once per 60 seconds')]
    public function testThrottle(): void
    {
        self::mockTime('2026-10-02 08:00:00');
        $client = static::createClient();
        [, $secret] = self::storeToken(TenantFixtures::of(self::getContainer())->create());
        $lastUsedAt = [];

        foreach (['08:00:00', '08:00:59', '08:01:00', '08:01:30'] as $time) {
            self::mockTime("2026-10-02 {$time}");
            self::assertSame(200, self::bearerRequest($client, $secret, 'GET', '/api/v1/me')->getStatusCode());
            $lastUsedAt[$time] = self::getContainer()->get(Connection::class)->fetchOne('SELECT last_used_at FROM api_token');
        }

        self::assertSame([
            '08:00:00' => '2026-10-02 08:00:00',
            '08:00:59' => '2026-10-02 08:00:00',
            '08:01:00' => '2026-10-02 08:01:00',
            '08:01:30' => '2026-10-02 08:01:00',
        ], $lastUsedAt);
    }
}
