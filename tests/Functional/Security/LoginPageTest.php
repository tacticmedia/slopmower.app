<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Security\SessionUser;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LoginPageTest extends WebTestCase
{
    use ResetDatabase;

    #[TestDox('For a signed-in user, /login shows the login and a sign-out form with the logout token')]
    public function testSignedInUserSeesTheLoginAndASignOutForm(): void
    {
        $client = self::createClient();
        $alice = TenantFixtures::fromContainer(self::getContainer())->localUser('alice');
        $client->loginUser(SessionUser::fromUser($alice), 'web');

        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('#signed-in-login', 'alice');
        $form = $crawler->filter('form[action="/logout"][method="post"]');
        self::assertCount(1, $form);
        self::assertSame('csrf-token', $form->filter('input[name="_csrf_token"]')->attr('value'));
    }
}
