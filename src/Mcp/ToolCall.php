<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Application\RateLimits;
use App\Domain\Error\DomainError;
use App\Domain\Error\ErrorCode;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use Sentry\State\HubInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * The adapter of docs/specs/mcp.md section 3 that each tool of `App\Mcp\Tool` runs in: the normalizers of REST, and every
 * failure as an isError result, because the SDK turns any other exception into a bare -32603.
 */
final readonly class ToolCall
{
    public function __construct(
        private RateLimits $rateLimits,
        private NormalizerInterface $normalizer,
        private LoggerInterface $logger,
        // The Sentry bundle runs in prod only.
        private ?HubInterface $sentryHub = null,
    ) {
    }

    /**
     * Consumes the rate limits of the call first.
     *
     * @param \Closure(): object $call
     *
     * @return array<string, mixed> The REST JSON of the result
     *
     * @throws ToolCallException With the Error JSON as its message
     */
    public function run(string $tool, \Closure $call): array
    {
        try {
            $this->rateLimits->consumeCall();
            $normalized = $this->normalizer->normalize($call(), 'json');
        } catch (DomainError $error) {
            throw new ToolCallException($this->errorJson($error->code(), $error->getMessage(), $error->details()), previous: $error);
        } catch (\Throwable $throwable) {
            $this->logger->error('The MCP tool {tool} failed.', ['tool' => $tool, 'exception' => $throwable]);
            $this->sentryHub?->captureException($throwable);

            throw new ToolCallException($this->errorJson(ErrorCode::InternalError, 'An unexpected error occurred.', null));
        }

        \assert(\is_array($normalized));

        return $normalized;
    }

    /**
     * @param array<string, mixed>|null $details
     */
    private function errorJson(ErrorCode $code, string $message, ?array $details): string
    {
        return json_encode(
            ['error' => ['code' => $code->value, 'message' => $message, 'details' => $this->normalizer->normalize($details, 'json')]],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
    }
}
