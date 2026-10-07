<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\SignInMethod;
use App\Security\LocalUserProvisioner;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Security\ConcurrentFirstSignIn;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class LocalUserProvisioningTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use ConcurrentFirstSignIn;
    use ResetDatabase;

    #[TestDox('The first sign-in creates one tenant and one local user')]
    public function testFirstSignInCreatesTenantAndUser(): void
    {
        $user = self::getContainer()->get(LocalUserProvisioner::class)->provision('jan');

        self::assertSame('jan', $user->getLogin());
        self::assertSame(SignInMethod::Local, $user->getSignInMethod());
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(1, (int) $connection->fetchOne('SELECT count(*) FROM tenant'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT count(*) FROM app_user'));
    }

    #[TestDox('A later sign-in finds the same user')]
    public function testLaterSignInFindsTheSameUser(): void
    {
        self::mockTime('2026-10-01 08:00:00');
        $provisioner = self::getContainer()->get(LocalUserProvisioner::class);
        $first = $provisioner->provision('jan');
        self::getContainer()->get('doctrine')->getManager()->clear();
        self::mockTime('2026-10-02 09:00:00');

        $later = $provisioner->provision('jan');

        self::assertTrue($first->getId()->equals($later->getId()));
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame([['count' => 1, 'last_sign_in_at' => '2026-10-02 09:00:00']], $connection->fetchAllAssociative(
            'SELECT count(*) OVER () AS count, last_sign_in_at FROM app_user',
        ));
    }

    #[TestDox('The loser of a concurrent first sign-in signs in as the user that the winner created')]
    public function testConcurrentFirstSignInFindsTheWinner(): void
    {
        $winnerId = self::winnerCommitsAtNextFlush(['sign_in_method' => 'local', 'login' => 'jan']);

        $user = self::getContainer()->get(LocalUserProvisioner::class)->provision('jan');

        self::assertTrue($winnerId->equals($user->getId()));
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(1, (int) $connection->fetchOne('SELECT count(*) FROM tenant'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT count(*) FROM app_user'));
    }
}
