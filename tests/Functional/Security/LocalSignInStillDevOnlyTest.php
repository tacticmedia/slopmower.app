<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LocalSignInStillDevOnlyTest extends WebTestCase
{
    use EnvironmentVariables;
    use ResetDatabase;

    #[TestDox('With GitHub configured and APP_LOCAL_SIGN_IN set, local sign-in still gives 404 outside dev')]
    public function testLocalSignInStaysOffOutsideDev(): void
    {
        $this->setEnvironmentVariable('APP_LOCAL_SIGN_IN', 'jan');
        $client = static::createClient();

        $client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/connect/github"]');
        self::assertSelectorNotExists('form');

        $client->request('POST', '/login/local', ['_csrf_token' => 'csrf-token'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/api/v1/me');
        self::assertResponseStatusCodeSame(401);
    }
}
