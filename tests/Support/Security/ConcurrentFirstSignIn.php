<?php

declare(strict_types=1);

namespace App\Tests\Support\Security;

use App\Domain\Identity\TenantId;
use App\Domain\Identity\UserId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;

/**
 * A concurrent first sign-in that wins, for KernelTestCase tests.
 */
trait ConcurrentFirstSignIn
{
    /**
     * At the next flush, after the sign-in found no user and before it inserts one, a second connection commits a new
     * tenant with the user.
     *
     * @param array<string, int|string> $user The app_user columns other than the ID, the tenant, and the times
     *
     * @return UserId Of the user that the second connection commits
     */
    private static function winnerCommitsAtNextFlush(array $user): UserId
    {
        $userId = UserId::generate();
        $parameters = self::getContainer()->get(Connection::class)->getParams();
        $commit = static function () use ($parameters, $user, $userId): void {
            $second = DriverManager::getConnection($parameters);
            $tenantId = TenantId::generate()->toRfc4122();
            $second->transactional(static function (Connection $second) use ($user, $userId, $tenantId): void {
                $second->insert('tenant', ['id' => $tenantId, 'created_at' => '2026-10-01 08:00:00', 'event_seq' => 0]);
                $second->insert('app_user', $user + ['id' => $userId->toRfc4122(), 'tenant_id' => $tenantId, 'created_at' => '2026-10-01 08:00:00', 'last_sign_in_at' => '2026-10-01 08:00:00']);
            });
            $second->close();
        };
        self::getContainer()->get(EntityManagerInterface::class)->getEventManager()->addEventListener(Events::preFlush, new class($commit) {
            public function __construct(private ?\Closure $commit)
            {
            }

            public function preFlush(): void
            {
                $commit = $this->commit;
                $this->commit = null;
                $commit?->__invoke();
            }
        });

        return $userId;
    }
}
