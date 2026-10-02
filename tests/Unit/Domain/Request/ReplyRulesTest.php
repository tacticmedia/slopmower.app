<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Request\Command\Reply;
use App\Tests\Support\Domain\InvalidInputAssertions;
use App\Tests\Support\Domain\RequestScenario;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReplyRulesTest extends KernelTestCase
{
    use InvalidInputAssertions;

    #[TestDox('A user reply with question true gives invalid_input')]
    public function testUserCannotAskAQuestion(): void
    {
        $scenario = RequestScenario::inState('in_progress');
        $command = new Reply($scenario->requestId(), 'Why?', question: true);

        self::assertInvalidInput(['question'], static fn () => $scenario->decide($command, RequestScenario::user()));
    }
}
