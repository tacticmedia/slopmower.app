<?php

declare(strict_types=1);

namespace App\Tests\Functional\Git;

use App\Domain\Git\CommitSha;
use App\Domain\Identity\ReviewId;
use App\Git\BareClone;
use App\Git\GitReader;
use App\Git\GitRunner;
use App\Git\PinRefs;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Git\GitRepositoryBuilder;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PinRefsSurvivePruneTest extends KernelTestCase
{
    use TemporaryDirectories;

    private GitRunner $git;

    private string $clone;

    private ReviewId $reviewId;

    private string $first;

    private string $reviewed;

    private string $linked;

    private string $unpinned;

    private string $rewritten;

    protected function setUp(): void
    {
        $this->git = new GitRunner(new NullLogger());
        $bareClone = new BareClone($this->git);
        $source = GitRepositoryBuilder::init($this->temporaryDirectory());
        $this->first = $source->commit(['README.md' => "Hello\n"]);
        $this->linked = $source->commit(['README.md' => "Hello again\n"]);
        $this->reviewed = $source->switch('feature')->commit(['feature.txt' => "Feature\n"]);
        $this->unpinned = $source->switch('temporary')->commit(['temporary.txt' => "Temporary\n"]);

        $this->clone = $this->temporaryDirectory().'/clone.git';
        $bareClone->init($this->clone);
        $bareClone->fetch($this->clone, $source->url());
        $this->reviewId = ReviewId::generate();
        $pins = new PinRefs($this->git);
        $pins->pinReview($this->clone, $this->reviewId, CommitSha::fromString($this->reviewed));
        $pins->pinCommit($this->clone, CommitSha::fromString($this->linked));

        $source->switch('main')->git('branch', '--delete', '--force', 'feature', 'temporary');
        $source->git('reset', '--hard', '--quiet', $this->first);
        $this->rewritten = $source->commit(['README.md' => "Rewritten\n"]);
        $bareClone->fetch($this->clone, $source->url());
    }

    #[TestDox('fetch --prune changes the heads but keeps the pins')]
    public function testPruneKeepsPins(): void
    {
        self::assertSame("refs/heads/main {$this->rewritten}\n", $this->git->run($this->clone, ['for-each-ref', '--format=%(refname) %(objectname)', 'refs/heads/']));
        self::assertEquals(
            [PinRefs::COMMITS.$this->linked => CommitSha::fromString($this->linked), PinRefs::REVIEWS.$this->reviewId => CommitSha::fromString($this->reviewed)],
            new PinRefs($this->git)->list($this->clone),
        );
    }

    #[TestDox('git gc --prune=now keeps the pinned commits readable')]
    public function testGcKeepsPinnedCommits(): void
    {
        $this->git->run($this->clone, ['gc', '--prune=now', '--quiet']);
        $reader = new GitReader($this->git);

        self::assertTrue($reader->hasCommit($this->clone, CommitSha::fromString($this->linked)));
        self::assertSame("Feature\n", $this->git->run($this->clone, ['cat-file', 'blob', "{$this->reviewed}:feature.txt"]));
        self::assertFalse($reader->hasCommit($this->clone, CommitSha::fromString($this->unpinned)), 'gc did not prune the unpinned commit');
    }
}
