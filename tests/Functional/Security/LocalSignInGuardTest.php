<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Security\LocalSignIn;
use App\Tests\Support\Database\ResetDatabase;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LocalSignInGuardTest extends WebTestCase
{
    use ResetDatabase;

    private const string VARIABLE = 'APP_LOCAL_SIGN_IN';

    /**
     * @var array{env: mixed, server: mixed}
     */
    private array $previousValues;

    protected function setUp(): void
    {
        $this->previousValues = ['env' => $_ENV[self::VARIABLE] ?? null, 'server' => $_SERVER[self::VARIABLE] ?? null];
        // The container reads $_ENV first, then $_SERVER, when the kernel boots.
        $_ENV[self::VARIABLE] = $_SERVER[self::VARIABLE] = 'jan';
    }

    #[After]
    protected function restoreVariable(): void
    {
        self::restore($_ENV, $this->previousValues['env']);
        self::restore($_SERVER, $this->previousValues['server']);
    }

    #[TestDox('In the test environment with APP_LOCAL_SIGN_IN set, POST /login/local gives 404 and /api/v1/me gives 401')]
    public function testLocalSignInDoesNotWorkOutsideDev(): void
    {
        $client = self::createClient();
        self::assertTrue(self::getContainer()->get(LocalSignIn::class)->isSetOutsideDev());

        $client->request('POST', '/login/local', ['_csrf_token' => 'csrf-token'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/api/v1/me');
        self::assertResponseStatusCodeSame(401);
    }

    #[TestDox('In the test environment with APP_LOCAL_SIGN_IN set, /login shows no local sign-in button and says no sign-in method is configured')]
    public function testLoginPageShowsNoLocalSignInButton(): void
    {
        $client = self::createClient();

        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#local-sign-in');
        self::assertSelectorExists('#no-sign-in-method');
    }

    /**
     * @param array<mixed> $superglobal
     */
    private static function restore(array &$superglobal, mixed $previousValue): void
    {
        if (null === $previousValue) {
            unset($superglobal[self::VARIABLE]);
        } else {
            $superglobal[self::VARIABLE] = $previousValue;
        }
    }
}
