<?php

declare(strict_types=1);

namespace App\Tests\Support\Contract;

use Symfony\Component\HttpFoundation\Response;

/**
 * Checks an /api/v1 response against docs/specs: status, JSON content type, `no-store` without an ETag, and schema.
 */
trait JsonApiAssertions
{
    /**
     * @param string $schema A schema file, with an optional JSON pointer: "lists.schema.json#/$defs/RepositoryPage"
     *
     * @return array<string, mixed> The decoded body
     */
    private static function assertApiResponse(Response $response, int $status, string $schema): array
    {
        $content = (string) $response->getContent();
        self::assertSame($status, $response->getStatusCode(), $content);
        self::assertSame('application/json; charset=utf-8', $response->headers->get('Content-Type'));

        if (!$response->headers->has('ETag')) {
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'), (string) $response->headers->get('Cache-Control'));
        }

        self::assertSame([], SchemaValidator::errors($content, $schema), "The body does not match {$schema}: {$content}");
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @return array{code: string, message: string, details: array<string, mixed>|null}
     */
    private static function assertApiError(Response $response, int $status, string $code): array
    {
        /** @var array{error: array{code: string, message: string, details: array<string, mixed>|null}} $body */
        $body = self::assertApiResponse($response, $status, 'error.schema.json');
        self::assertSame($code, $body['error']['code']);

        return $body['error'];
    }
}
