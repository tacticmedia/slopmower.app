<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Tests\Support\Contract\SchemaValidator;
use App\Tests\Unit\Domain\SpecFiles;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SchemaValidatorTest extends KernelTestCase
{
    #[TestDox('A spec fixture validates, and the same fixture with a broken referenced definition does not')]
    public function testReportsViolationsInReferencedSchemas(): void
    {
        $fixture = SpecFiles::fixture('me.local-user.json');
        self::assertSame([], SchemaValidator::errors(json_encode($fixture, \JSON_THROW_ON_ERROR), 'me.schema.json'));

        \assert(\is_array($fixture['caller']));
        $fixture['caller']['userId'] = 'not-a-ulid';

        self::assertNotSame([], SchemaValidator::errors(json_encode($fixture, \JSON_THROW_ON_ERROR), 'me.schema.json'));
    }
}
