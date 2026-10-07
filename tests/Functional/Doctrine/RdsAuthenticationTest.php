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
use TacticMedia\RdsAuth\ConfiguredPasswordOutdated;

final class RdsAuthenticationTest extends KernelTestCase
{
    use EnvironmentVariables;

    #[TestDox('In IAM mode, the lock store signs in as the IAM user with a token')]
    public function testLockStoreSignsInWithAnIamToken(): void
    {
        $this->setEnvironmentVariable('AWS_REGION', 'ap-southeast-2');
        $this->setEnvironmentVariable('RDS_IAM_USERNAME', 'rds_iam_probe');
        $this->setEnvironmentVariable('AWS_ACCESS_KEY_ID', 'AKIAIOSFODNN7EXAMPLE');
        $this->setEnvironmentVariable('AWS_SECRET_ACCESS_KEY', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY');
        // The middleware requires TLS only when the URL sets no sslmode, and the local PostgreSQL has no TLS.
        $this->setEnvironmentVariable('DATABASE_URL', $_SERVER['DATABASE_URL'].'&sslmode=disable');

        $lock = self::getContainer()->get(LockFactory::class)->createLock('rds-iam-probe', ttl: null);

        try {
            $lock->acquire();
            self::fail('The lock store signed in without the IAM token.');
        } catch (LockAcquiringException $exception) {
            self::assertStringContainsString('password authentication failed for user "rds_iam_probe"', (string) $exception->getPrevious()?->getMessage());
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
}
