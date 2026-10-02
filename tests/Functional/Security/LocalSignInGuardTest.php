<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\DataCollector\LoggerDataCollector;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\VarDumper\Cloner\Data;

final class LocalSignInGuardTest extends WebTestCase
{
    use EnvironmentVariables;
    use ResetDatabase;

    private const string VARIABLE = 'APP_LOCAL_SIGN_IN';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnvironmentVariable(self::VARIABLE, 'jan');
        // LocalSignInStillDevOnlyTest covers the same guard with a GitHub App.
        $this->setEnvironmentVariable('GITHUB_APP_CLIENT_ID', '');
    }

    #[TestDox('In the test environment with APP_LOCAL_SIGN_IN set, POST /login/local gives 404 and /api/v1/me gives 401')]
    public function testLocalSignInIsOffOutsideDev(): void
    {
        $client = static::createClient();

        $client->request('POST', '/login/local', ['_csrf_token' => 'csrf-token'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/api/v1/me');
        self::assertResponseStatusCodeSame(401);
    }

    #[TestDox('Outside dev, a set APP_LOCAL_SIGN_IN logs a warning')]
    public function testLogsWarningOutsideDev(): void
    {
        $client = static::createClient();
        $client->enableProfiler();

        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form');
        $profile = $client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('logger');
        self::assertInstanceOf(LoggerDataCollector::class, $collector);
        $warnings = array_filter($collector->getProcessedLogs(), static function (array $log): bool {
            $message = $log['message'] instanceof Data ? $log['message']->getValue() : null;

            return 'WARNING' === $log['priorityName'] && \is_string($message) && str_contains($message, self::VARIABLE);
        });
        self::assertCount(1, $warnings);
    }
}
