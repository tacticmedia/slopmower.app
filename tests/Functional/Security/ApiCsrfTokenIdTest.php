<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ApiCsrfTokenIdTest extends KernelTestCase
{
    private const string DOUBLE_SUBMIT_TOKEN = 'k4Vq9ZrT2bXw7LmN3pYs8GdH';

    /**
     * @return iterable<string, array{array<string, string>, string, bool}>
     */
    public static function requests(): iterable
    {
        yield 'Sec-Fetch-Site same-origin' => [['HTTP_SEC_FETCH_SITE' => 'same-origin'], 'csrf-token', true];
        yield 'csrf-token header not equal to the token' => [
            ['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_CSRF_TOKEN' => strrev(self::DOUBLE_SUBMIT_TOKEN)],
            self::DOUBLE_SUBMIT_TOKEN,
            false,
        ];
    }

    /**
     * @param array<string, string> $server
     */
    #[DataProvider('requests')]
    #[TestDox('The token ID api passes with a same-origin proof, unless a csrf-token header differs from the token')]
    public function testApiTokenIdIsStatelessWithHeaderCheck(array $server, string $tokenValue, bool $valid): void
    {
        self::getContainer()->get(RequestStack::class)->push(Request::create('http://localhost/api/v1/repositories', 'POST', server: $server));

        self::assertSame($valid, self::getContainer()->get(CsrfTokenManagerInterface::class)->isTokenValid(new CsrfToken('api', $tokenValue)));
    }
}
