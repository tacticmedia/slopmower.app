<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Domain\Error\DomainError;
use App\Domain\Error\ErrorCode;
use App\Domain\Error\NotFound;
use App\Domain\Error\RateLimited;
use App\Http\SentryClientErrorFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Sentry\Event;
use Sentry\EventHint;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\LazyResponseException;
use Symfony\Component\Security\Core\Exception\LogoutException;

final class SentryClientErrorFilterTest extends KernelTestCase
{
    #[TestDox('Drops the event of an exception whose response is below 500')]
    #[DataProvider('clientErrors')]
    public function testDropsClientErrors(\Throwable $exception, string $path): void
    {
        self::assertNull(self::filter($path)(Event::createEvent(), EventHint::fromArray(['exception' => $exception])));
    }

    /**
     * @return iterable<string, array{\Throwable, string}>
     */
    public static function clientErrors(): iterable
    {
        yield 'unknown API route, 404' => [new NotFoundHttpException(), '/api/v1/no-such-route'];
        yield 'unknown page, 404' => [new NotFoundHttpException(), '/no-such-page'];
        yield 'invalid Host header, 400' => [new BadRequestHttpException('Invalid Host.', new SuspiciousOperationException('Invalid Host.')), '/api/v1/me'];
        yield 'authentication, 401' => [new AuthenticationException(), '/api/v1/me'];
        yield 'access denied in the chain, 401 or 403' => [new \RuntimeException('Wrapped.', 0, new AccessDeniedException()), '/settings'];
        yield 'logout without CSRF token, 403' => [new LogoutException(), '/logout'];
        yield 'lazy firewall response, 401' => [new LazyResponseException(new Response('', Response::HTTP_UNAUTHORIZED)), '/api/v1/me'];
        yield 'rate_limited on /_mcp, 429' => [new RateLimited(60, 600, 0), '/_mcp'];
    }

    #[TestDox('Drops the event of an API error with a 4xx code, and keeps the event of internal_error')]
    #[DataProvider('errorCodes')]
    public function testFiltersApiErrorsByCode(ErrorCode $code, bool $kept): void
    {
        $event = Event::createEvent();

        self::assertSame($kept ? $event : null, self::filter('/api/v1/repositories')($event, EventHint::fromArray(['exception' => self::domainError($code)])));
    }

    /**
     * @return iterable<string, array{ErrorCode, bool}>
     */
    public static function errorCodes(): iterable
    {
        foreach (ErrorCode::cases() as $code) {
            yield $code->value => [$code, ErrorCode::InternalError === $code];
        }
    }

    #[TestDox('Keeps the event of an exception whose response is 500 or above')]
    #[DataProvider('serverErrors')]
    public function testKeepsServerErrors(\Throwable $exception, string $path): void
    {
        $event = Event::createEvent();

        self::assertSame($event, self::filter($path)($event, EventHint::fromArray(['exception' => $exception])));
    }

    /**
     * @return iterable<string, array{\Throwable, string}>
     */
    public static function serverErrors(): iterable
    {
        yield '503' => [new ServiceUnavailableHttpException(), '/_mcp'];
        yield 'HTTP 409 that the API does not map, 500' => [new ConflictHttpException(), '/api/v1/repositories'];
        yield 'API error outside /api/v1, 500' => [new NotFound(), '/settings'];
        yield 'exception without a status, 500' => [new \RuntimeException('Disk full.'), '/api/v1/repositories'];
    }

    #[TestDox('Keeps the event of a 4xx exception without a current request, as in a Messenger worker or a console command')]
    public function testKeepsEventsWithoutARequest(): void
    {
        $event = Event::createEvent();

        self::assertSame($event, (new SentryClientErrorFilter(new RequestStack()))($event, EventHint::fromArray(['exception' => new NotFound()])));
    }

    private static function filter(string $path): SentryClientErrorFilter
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create($path));

        return new SentryClientErrorFilter($requestStack);
    }

    private static function domainError(ErrorCode $code): DomainError
    {
        return new class($code) extends DomainError {
            public function __construct(private readonly ErrorCode $errorCode)
            {
                parent::__construct('An API error.');
            }

            public function code(): ErrorCode
            {
                return $this->errorCode;
            }

            public function details(): ?array
            {
                return null;
            }
        };
    }
}
