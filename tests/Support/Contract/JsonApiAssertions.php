<?php

declare(strict_types=1);

namespace App\Tests\Support\Contract;

use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;

trait JsonApiAssertions
{
    /**
     * Asserts the JSON content type of docs/specs/README.md section 2.1 and the schema, then decodes the body.
     *
     * @param string $schema see {@see SchemaValidator::errors()}
     *
     * @return array<mixed>
     */
    public static function assertJsonMatchesSchema(Response $response, string $schema): array
    {
        Assert::assertSame('application/json; charset=utf-8', $response->headers->get('Content-Type'));
        $json = (string) $response->getContent();
        Assert::assertSame([], SchemaValidator::errors($json, $schema), \sprintf('The body does not match %s: %s', $schema, $json));

        $body = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        Assert::assertIsArray($body);

        return $body;
    }
}
