<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Error;

use App\Domain\Error\ErrorCode;
use App\Tests\Support\Specs\SpecsFiles;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ErrorCodeTest extends KernelTestCase
{
    use SpecsFiles;

    #[TestDox('The enum equals the error codes in docs/specs')]
    public function testEqualsTheDocumentedCodes(): void
    {
        $documented = self::specsJson('schemas/common.schema.json')['$defs']['ErrorCode']['anyOf'][0]['enum'];

        self::assertSame($documented, array_map(static fn (ErrorCode $code): string => $code->value, ErrorCode::cases()));
    }
}
