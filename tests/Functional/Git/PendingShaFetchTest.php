<?php

declare(strict_types=1);

namespace App\Tests\Functional\Git;

use App\Domain\Git\CommitSha;
use App\Git\BareClone;
use App\Git\GitRunner;
use App\Git\PinRefs;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Git\GitRepositoryBuilder;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PendingShaFetchTest extends KernelTestCase
{
    use TemporaryDirectories;

    private GitRunner $git;

    private GitRepositoryBuilder $source;

    private string $onBranch;

    private string $outsideHeads;

    protected function setUp(): void
    {
        $this->git = new GitRunner(new NullLogger());
        $this->source = GitRepositoryBuilder::init($this->temporaryDirectory());
        $this->onBranch = $this->source->commit(['README.md' => "One\n"]);
        $this->source->commit(['README.md' => "Two\n"]);
        $this->outsideHeads = $this->source->switch('pull')->commit(['pull.txt' => "Pull\n"]);
        $this->source->git('update-ref', 'refs/pull/1/head', $this->outsideHeads);
        $this->source->switch('main')->git('branch', '--delete', '--force', 'pull');
    }

    #[TestDox('A pending SHA that the source has lands in refs/holistic-review/commits')]
    public function testKnownShasLandInPins(): void
    {
        $clone = $this->fetchClone([$this->onBranch, $this->outsideHeads]);

        self::assertEqualsCanonicalizing(
            [PinRefs::COMMITS.$this->onBranch, PinRefs::COMMITS.$this->outsideHeads],
            array_keys(new PinRefs($this->git)->list($clone)),
        );
    }

    #[TestDox('An unknown pending SHA does not stop the heads or the other pending SHAs')]
    public function testUnknownShaIsSkipped(): void
    {
        $clone = $this->fetchClone([str_repeat('1', 40), $this->outsideHeads]);

        self::assertSame(
            ['refs/heads/main', PinRefs::COMMITS.$this->outsideHeads],
            explode("\n", trim($this->git->run($clone, ['for-each-ref', '--format=%(refname)']))),
        );
    }

    /**
     * @param list<string> $pendingShas
     */
    private function fetchClone(array $pendingShas): string
    {
        $clone = $this->temporaryDirectory().'/clone.git';
        $bareClone = new BareClone($this->git);
        $bareClone->init($clone);
        $bareClone->fetch($clone, $this->source->url(), array_map(CommitSha::fromString(...), $pendingShas));

        return $clone;
    }
}
