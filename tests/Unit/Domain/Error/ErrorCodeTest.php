<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Error;

use App\Domain\Error\ErrorCode;
use App\Tests\Unit\Domain\SpecFiles;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ErrorCodeTest extends KernelTestCase
{
    #[TestDox('The enum equals the error codes in docs/specs')]
    public function testEnumEqualsTheErrorCodesInSpecs(): void
    {
        $specCodes = SpecFiles::schema('common.schema.json')['$defs']['ErrorCode']['anyOf'][0]['enum'];
        $enumCodes = array_map(static fn (ErrorCode $code): string => $code->value, ErrorCode::cases());
        sort($specCodes);
        sort($enumCodes);

        self::assertSame($specCodes, $enumCodes);
    }
}
