<?php

declare(strict_types=1);

namespace App\Tests\Functional\Git;

use App\Domain\Git\CommitSha;
use App\Domain\Identity\ReviewId;
use App\Git\BareClone;
use App\Git\GitFailed;
use App\Git\GitRunner;
use App\Git\PinRefs;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Git\GitRepositoryBuilder;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PinRefsTest extends KernelTestCase
{
    use TemporaryDirectories;

    private PinRefs $pins;

    private string $clone;

    private CommitSha $first;

    private CommitSha $second;

    protected function setUp(): void
    {
        $git = new GitRunner(new NullLogger());
        $source = GitRepositoryBuilder::init($this->temporaryDirectory());
        $this->first = CommitSha::fromString($source->commit(['README.md' => "One\n"]));
        $this->second = CommitSha::fromString($source->commit(['README.md' => "Two\n"]));
        $this->clone = $this->temporaryDirectory().'/clone.git';
        $bareClone = new BareClone($git);
        $bareClone->init($this->clone);
        $bareClone->fetch($this->clone, $source->url());
        $this->pins = new PinRefs($git);
    }

    #[TestDox('Creating a pin twice is idempotent')]
    public function testCreatingTwiceIsIdempotent(): void
    {
        $reviewId = ReviewId::generate();

        $this->pins->pinReview($this->clone, $reviewId, $this->first);
        $this->pins->pinReview($this->clone, $reviewId, $this->first);

        self::assertEquals([PinRefs::REVIEWS.$reviewId => $this->first], $this->pins->list($this->clone));
    }

    #[TestDox('Moving a pin to another commit is refused')]
    public function testMovingIsRefused(): void
    {
        $reviewId = ReviewId::generate();
        $this->pins->pinReview($this->clone, $reviewId, $this->first);

        try {
            $this->pins->pinReview($this->clone, $reviewId, $this->second);
            self::fail('The pin moved.');
        } catch (GitFailed) {
        }

        self::assertEquals([PinRefs::REVIEWS.$reviewId => $this->first], $this->pins->list($this->clone));
    }

    #[TestDox('Deleting a pin removes the ref')]
    public function testDeletingRemovesTheRef(): void
    {
        $this->pins->pinCommit($this->clone, $this->first);

        $this->pins->unpinCommit($this->clone, $this->first);

        self::assertSame([], $this->pins->list($this->clone));
    }
}
