<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Error\DomainError;
use App\Domain\Error\ErrorCode;
use App\Domain\Error\InvalidInput;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * The Error body of docs/specs/README.md section 4 for every failure under /api/v1. Runs after the firewall exception
 * listener at 1, which answers 401, and after the framework logs the exception at 0.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: -1)]
final class ApiExceptionListener
{
    public function __construct(private readonly NormalizerInterface $normalizer)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        if (!ApiRequest::matches($request)) {
            return;
        }

        $throwable = $event->getThrowable();

        $event->setResponse(match (true) {
            $throwable instanceof DomainError => $this->domainError($throwable, self::status($throwable->code())),
            $throwable instanceof HttpExceptionInterface => $this->httpError($throwable, $request),
            default => self::internalError(),
        });
    }

    private function httpError(HttpExceptionInterface $exception, Request $request): Response
    {
        return match ($exception->getStatusCode()) {
            Response::HTTP_BAD_REQUEST, Response::HTTP_UNPROCESSABLE_ENTITY => $this->invalidInput($exception),
            Response::HTTP_FORBIDDEN => self::error(Response::HTTP_FORBIDDEN, ErrorCode::Forbidden, '' !== $exception->getMessage() ? $exception->getMessage() : 'Access denied.'),
            Response::HTTP_NOT_FOUND => self::error(Response::HTTP_NOT_FOUND, ErrorCode::NotFound, 'The resource does not exist.'),
            Response::HTTP_METHOD_NOT_ALLOWED => self::methodNotAllowed($exception, $request),
            Response::HTTP_REQUEST_ENTITY_TOO_LARGE => self::error(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, ErrorCode::InvalidInput, 'The body is larger than 1 MiB.'),
            Response::HTTP_UNSUPPORTED_MEDIA_TYPE => self::error(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, ErrorCode::InvalidInput, 'The body must be JSON with the content type application/json.'),
            default => self::internalError(),
        };
    }

    /**
     * Unparsable JSON, and a query value of the wrong type, give 400. A query violation without a constraint is a
     * type error.
     */
    private function invalidInput(HttpExceptionInterface $exception): Response
    {
        $previous = $exception->getPrevious();

        if (!$previous instanceof ValidationFailedException) {
            return Response::HTTP_BAD_REQUEST === $exception->getStatusCode()
                ? $this->domainError(InvalidInput::field('', $previous instanceof NotEncodableValueException ? 'The body is not valid JSON.' : 'The request is malformed.'), Response::HTTP_BAD_REQUEST)
                : $this->domainError(InvalidInput::field('', 'The body must be a JSON object.'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $violations = [...$previous->getViolations()];
        $isTypeError = $previous->getValue() instanceof QueryInput
            && array_any($violations, static fn (ConstraintViolationInterface $violation): bool => null === $violation->getConstraint());
        $fields = array_map(static fn (ConstraintViolationInterface $violation): array => [
            'field' => self::dottedPath($violation->getPropertyPath()),
            'message' => (string) $violation->getMessage(),
        ], $violations);

        return $this->domainError(
            [] === $fields ? InvalidInput::field('', 'The input is invalid.') : new InvalidInput($fields),
            $isTypeError ? Response::HTTP_BAD_REQUEST : Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    private function domainError(DomainError $error, int $status): Response
    {
        return self::error($status, $error->code(), $error->getMessage(), details: $this->normalizer->normalize($error->details(), 'json'));
    }

    private static function methodNotAllowed(HttpExceptionInterface $exception, Request $request): Response
    {
        $allow = $exception->getHeaders()['Allow'] ?? '';
        \assert(\is_string($allow));

        return self::error(Response::HTTP_METHOD_NOT_ALLOWED, ErrorCode::InvalidInput, "Method {$request->getMethod()} is not allowed. Allowed methods: {$allow}.", ['Allow' => $allow]);
    }

    private static function internalError(): Response
    {
        return self::error(Response::HTTP_INTERNAL_SERVER_ERROR, ErrorCode::InternalError, 'An unexpected error occurred.');
    }

    /**
     * @param array<string, string> $headers
     */
    private static function error(int $status, ErrorCode $code, string $message, array $headers = [], mixed $details = null): Response
    {
        return new JsonResponse(['error' => ['code' => $code->value, 'message' => $message, 'details' => $details]], $status, $headers);
    }

    private static function status(ErrorCode $code): int
    {
        return match ($code) {
            ErrorCode::Unauthorized => Response::HTTP_UNAUTHORIZED,
            ErrorCode::Forbidden => Response::HTTP_FORBIDDEN,
            ErrorCode::NotFound => Response::HTTP_NOT_FOUND,
            ErrorCode::InvalidInput, ErrorCode::CommitNotFound => Response::HTTP_UNPROCESSABLE_ENTITY,
            ErrorCode::InvalidTransition, ErrorCode::ReviewClosed, ErrorCode::CommitsNotAvailable, ErrorCode::RepositoryUnavailable => Response::HTTP_CONFLICT,
            ErrorCode::InternalError => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
    }

    /**
     * `requestIds[1]` becomes `requestIds.1`.
     */
    private static function dottedPath(string $propertyPath): string
    {
        return ltrim((string) preg_replace('/\[([^]]*)]/', '.$1', $propertyPath), '.');
    }
}
