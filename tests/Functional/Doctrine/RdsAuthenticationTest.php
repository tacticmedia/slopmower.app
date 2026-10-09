<?php

declare(strict_types=1);

namespace App\Tests\Functional\Doctrine;

use App\Tests\Support\Environment\EnvironmentVariables;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Exception\TransportException;
use TacticMedia\RdsAuth\ConfiguredPasswordOutdated;

final class RdsAuthenticationTest extends KernelTestCase
{
    use EnvironmentVariables;

    #[TestDox('In IAM mode, the lock store signs in as the IAM user with a token')]
    public function testLockStoreSignsInWithAnIamToken(): void
    {
        $this->enableIamAuthentication();

        $lock = self::getContainer()->get(LockFactory::class)->createLock('rds-iam-probe', ttl: null);

        try {
            $lock->acquire();
            self::fail('The lock store signed in without the IAM token.');
        } catch (LockAcquiringException $exception) {
            self::assertStringContainsString('password authentication failed for user "rds_iam_probe"', (string) $exception->getPrevious()?->getMessage());
        }
    }

    #[TestDox('In IAM mode, the Messenger queue signs in as the IAM user with a token')]
    public function testMessengerQueueSignsInWithAnIamToken(): void
    {
        $this->enableIamAuthentication();

        // The test environment keeps async in memory. failed has the same doctrine://default connection.
        $transport = self::getContainer()->get('messenger.transport.failed');

        try {
            $transport->get();
            self::fail('The Messenger queue signed in without the IAM token.');
        } catch (TransportException $exception) {
            self::assertStringContainsString('password authentication failed for user "rds_iam_probe"', $exception->getMessage());
        }
    }

    #[TestDox('A raw password with URL-reserved characters signs in')]
    public function testRawPasswordWithUrlReservedCharactersSignsIn(): void
    {
        $role = 'raw_password_probe'.($_SERVER['TEST_TOKEN'] ?? '');
        $password = 'p%%a%s#s?w$o\r&d;=!1';
        $owner = self::getContainer()->get('doctrine.dbal.default_connection');
        $owner->executeStatement(sprintf('DROP ROLE IF EXISTS %s', $role));
        $owner->executeStatement(sprintf('CREATE ROLE %s LOGIN PASSWORD %s', $role, $owner->quote($password)));

        try {
            self::ensureKernelShutdown();
            $this->setEnvironmentVariable('DATABASE_USER', $role);
            $this->setEnvironmentVariable('DATABASE_PASSWORD', $password);

            self::assertSame($role, self::getContainer()->get('doctrine.dbal.default_connection')->fetchOne('SELECT current_user'));
        } finally {
            // Closes the session of the role.
            self::ensureKernelShutdown();
            $owner->executeStatement(sprintf('DROP ROLE %s', $role));
        }
    }

    #[TestDox('An outdated master password logs an error with the connection details')]
    public function testOutdatedMasterPasswordIsLoggedAsAnError(): void
    {
        $secretArn = 'arn:aws:secretsmanager:ap-southeast-2:123456789012:secret:rds!db-probe';

        self::getContainer()->get('event_dispatcher')->dispatch(new ConfiguredPasswordOutdated($secretArn, 'db.example.com', 5432, 'app', 'app', '08006'));

        $records = self::getContainer()->get('monolog.handler.records');
        $errors = array_values(array_filter($records->getRecords(), static fn (LogRecord $record): bool => Level::Error === $record->level));
        self::assertCount(1, $errors);
        self::assertSame(['secretArn' => $secretArn, 'host' => 'db.example.com', 'port' => 5432, 'dbname' => 'app', 'user' => 'app', 'sqlState' => '08006'], $errors[0]->context);
    }

    private function enableIamAuthentication(): void
    {
        $this->setEnvironmentVariable('AWS_REGION', 'ap-southeast-2');
        $this->setEnvironmentVariable('RDS_IAM_USERNAME', 'rds_iam_probe');
        $this->setEnvironmentVariable('AWS_ACCESS_KEY_ID', 'AKIAIOSFODNN7EXAMPLE');
        $this->setEnvironmentVariable('AWS_SECRET_ACCESS_KEY', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY');
        // The middleware requires TLS only when sslmode is unset, and the local PostgreSQL has no TLS.
        $this->setEnvironmentVariable('DATABASE_SSLMODE', 'disable');
    }
}
