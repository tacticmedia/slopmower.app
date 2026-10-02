<?php

declare(strict_types=1);

namespace App\Tests\Functional\Git;

use App\Domain\Error\CommitNotFound;
use App\Domain\Error\ErrorCode;
use App\Domain\Git\RefName;
use App\Git\BareClone;
use App\Git\GitReader;
use App\Git\GitRunner;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Git\GitRepositoryBuilder;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UnknownRefTest extends KernelTestCase
{
    use TemporaryDirectories;

    #[TestDox('An unknown ref gives commit_not_found')]
    public function testUnknownRefGivesCommitNotFound(): void
    {
        $git = new GitRunner(new NullLogger());
        $source = GitRepositoryBuilder::init($this->temporaryDirectory());
        $source->commit(['README.md' => "Hello\n"]);
        $clone = $this->temporaryDirectory().'/clone.git';
        $bareClone = new BareClone($git);
        $bareClone->init($clone);
        $bareClone->fetch($clone, $source->url());

        try {
            new GitReader($git)->resolveCommit($clone, RefName::fromString('no-such-branch'));
            self::fail('The unknown ref resolved.');
        } catch (CommitNotFound $error) {
            self::assertSame(ErrorCode::CommitNotFound, $error->code());
            self::assertSame(['commit' => 'no-such-branch'], $error->details());
        }
    }
}
