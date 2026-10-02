<?php

declare(strict_types=1);

namespace App\Tests\Unit\Git;

use App\Domain\Error\InvalidInput;
use App\Domain\Git\RefName;
use App\Git\GitReader;
use App\Git\GitRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RefInjectionTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function optionLikeRefs(): iterable
    {
        yield 'option with value' => ['--upload-pack=x'];
        yield 'short option' => ['-h'];
        yield 'range' => ['a..b'];
        yield 'reflog entry' => ['HEAD@{1}'];
    }

    /**
     * A ref reaches git only as a RefName. Git would answer a missing clone with commit_not_found instead.
     */
    #[DataProvider('optionLikeRefs')]
    #[TestDox('Rejects --upload-pack=x, -h, a..b, and HEAD@{1} before git runs')]
    public function testRejectsRefBeforeGitRuns(string $ref): void
    {
        $reader = new GitReader(new GitRunner(new NullLogger()));

        $this->expectException(InvalidInput::class);

        $reader->resolveCommit('/nonexistent/clone.git', RefName::fromString($ref));
    }
}
