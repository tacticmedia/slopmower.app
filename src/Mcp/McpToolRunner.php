<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Domain\Error\DomainError;
use App\Domain\Error\ErrorCode;
use App\Domain\Error\InvalidInput;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The adapter of docs/specs/mcp.md section 3: the normalizers of REST, and every failure as an isError result. The SDK
 * turns any other exception into a bare -32603.
 */
final class McpToolRunner
{
    /** The `#[Schema]` patterns of docs/specs/mcp.md section 3. */
    public const string ULID = '^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$';
    public const string SHA_PREFIX = '^[0-9a-f]{7,64}$';

    public function __construct(
        private readonly NormalizerInterface $normalizer,
        private readonly ValidatorInterface $validator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param \Closure(): object $query
     *
     * @return array<string, mixed> The REST JSON of the result
     *
     * @throws ToolCallException With the Error JSON as its message
     */
    public function run(string $tool, \Closure $query): array
    {
        try {
            $normalized = $this->normalizer->normalize($query(), 'json');
        } catch (DomainError $error) {
            throw new ToolCallException($this->errorJson($error->code(), $error->getMessage(), $error->details()), previous: $error);
        } catch (\Throwable $throwable) {
            $this->logger->error('The MCP tool {tool} failed.', ['tool' => $tool, 'exception' => $throwable]);

            throw new ToolCallException($this->errorJson(ErrorCode::InternalError, 'An unexpected error occurred.', null));
        }

        \assert(\is_array($normalized));

        return $normalized;
    }

    /**
     * Applies the constraints of a REST input to the arguments of a tool.
     *
     * @throws InvalidInput
     */
    public function validate(object $input): void
    {
        $violations = array_map(static fn (ConstraintViolationInterface $violation): array => [
            'field' => $violation->getPropertyPath(),
            'message' => (string) $violation->getMessage(),
        ], [...$this->validator->validate($input)]);

        if ([] !== $violations) {
            throw new InvalidInput($violations);
        }
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
