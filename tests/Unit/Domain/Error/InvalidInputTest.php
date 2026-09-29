<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Error;

use App\Domain\Error\InvalidInput;
use App\Domain\Error\Violation;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InvalidInputTest extends KernelTestCase
{
    #[TestDox('Returns every violation in the order given as details and in the message')]
    public function testReturnsEveryViolationInOrder(): void
    {
        $error = InvalidInput::fromViolations([
            new Violation('anchor.range.endLine', 'must be 1 or more'),
            new Violation('body', 'must not be empty'),
        ]);

        self::assertSame(['violations' => [
            ['field' => 'anchor.range.endLine', 'message' => 'must be 1 or more'],
            ['field' => 'body', 'message' => 'must not be empty'],
        ]], $error->details());
        self::assertSame('Invalid input: anchor.range.endLine must be 1 or more; body must not be empty', $error->getMessage());
    }

    #[TestDox('Keeps the empty field of the whole input and calls it input in the message')]
    public function testKeepsTheEmptyFieldOfTheWholeInput(): void
    {
        $error = InvalidInput::field('', 'must be a JSON object');

        self::assertSame(['violations' => [['field' => '', 'message' => 'must be a JSON object']]], $error->details());
        self::assertSame('Invalid input: input must be a JSON object', $error->getMessage());
    }
}
