<?php

declare(strict_types=1);

namespace App\Tests\Functional\EventStore;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\EventStore\CommandFixtures;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RebuildProjectionsCommandTest extends KernelTestCase
{
    use CommandFixtures;
    use ResetDatabase;

    #[TestDox('--tenant rebuilds only that tenant')]
    public function testRebuildsOnlyTheNamedTenant(): void
    {
        $named = self::enterNewTenant();
        self::startReview(self::newRepository($named));
        $other = self::enterNewTenant('octocat');
        self::startReview(self::newRepository($other));
        $before = self::storedRows($named);
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE review SET title = 'Tampered'");

        self::rebuild($named->toString())->assertCommandIsSuccessful();

        self::assertSame($before, self::storedRows($named));
        self::assertSame(['Tampered'], array_column(self::storedRows($other)['review'], 'title'));
    }

    #[TestDox('--tenant with an unknown ID or a value that is not a ULID fails with a message')]
    #[TestWith(['01ARZ3NDEKTSV4RRFFQ69G5FAV', 'No tenant has the ID'], 'unknown')]
    #[TestWith(['not-a-ulid', '--tenant: Must be a ULID'], 'not a ULID')]
    public function testRefusesTheTenant(string $tenant, string $message): void
    {
        self::bootKernel();

        $tester = self::rebuild($tenant);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString($message, $tester->getDisplay());
    }

    private static function rebuild(string $tenant): CommandTester
    {
        $tester = new CommandTester(new Application(self::$kernel ?? self::fail('No kernel.'))->find('app:projections:rebuild'));
        $tester->execute(['--tenant' => $tenant]);

        return $tester;
    }
}
