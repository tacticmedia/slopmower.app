<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Event\Codec;

use App\Domain\Event\Codec\PayloadJson;
use App\Tests\Unit\Domain\ViolatedFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PayloadJsonTest extends KernelTestCase
{
    use ViolatedFields;

    #[TestDox('Encodes compact JSON with raw slashes and Unicode and with zero fractions')]
    public function testEncodesCompactJson(): void
    {
        self::assertSame(
            '{"path":"src/Caf'."\u{00E9}".'/Totals.php","fqn":"App\\\\Totals::total()","confidence":1.0,"lines":["a","b"],"range":{"startLine":1,"endLine":2},"note":null}',
            PayloadJson::encode([
                'path' => 'src/Caf'."\u{00E9}".'/Totals.php',
                'fqn' => 'App\Totals::total()',
                'confidence' => 1.0,
                'lines' => ['a', 'b'],
                'range' => ['startLine' => 1, 'endLine' => 2],
                'note' => null,
            ]),
        );
    }

    #[TestDox('Encodes an empty payload as an object and an empty list as an array')]
    public function testEncodesAnEmptyPayloadAsAnObject(): void
    {
        self::assertSame('{}', PayloadJson::encode([]));
        self::assertSame('{"before":[]}', PayloadJson::encode(['before' => []]));
    }

    #[TestDox('Decodes a JSON object with surrounding whitespace into an array')]
    public function testDecodesAJsonObject(): void
    {
        self::assertSame([], PayloadJson::decode('{}'));
        self::assertSame(['a' => [1, ['b' => true]], 'c' => 1.0], PayloadJson::decode(" \t\r\n{\"a\":[1,{\"b\":true}],\"c\":1.0}\n"));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonObjects(): iterable
    {
        yield 'empty' => [''];
        yield 'empty list' => ['[]'];
        yield 'list' => ['["a"]'];
        yield 'string' => ['"{}"'];
        yield 'null' => ['null'];
        yield 'number' => ['1'];
        yield 'unclosed object' => ['{"a":1'];
        yield 'missing value' => ['{"a":}'];
        yield 'trailing text' => ['{"a":1} x'];
        yield 'second object' => ['{}{}'];
        yield 'invalid UTF-8' => ["{\"a\":\"\xC3\x28\"}"];
    }

    #[DataProvider('nonObjects')]
    #[TestDox('Rejects invalid JSON and JSON that is not an object, naming the field')]
    public function testRejectsJsonThatIsNotAnObject(string $json): void
    {
        self::assertSame(['payload'], self::violatedFields(static fn (): array => PayloadJson::decode($json)));
        self::assertSame(['actor'], self::violatedFields(static fn (): array => PayloadJson::decode($json, 'actor')));
    }
}
