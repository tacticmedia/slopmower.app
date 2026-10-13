<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Git\CommitSha;
use App\Domain\Identity\PullRequestId;
use App\Domain\Identity\TenantId;
use App\Domain\Request\PullRequestState;
use App\Repository\PullRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A linked pull request of a GitHub repository, as GitHub last reported it: docs/specs/webhooks-and-live-updates.md,
 * "Pull request state". The GitHub fields are null until the first read.
 */
#[ORM\Entity(repositoryClass: PullRequestRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_pull_request', columns: ['tenant_id', 'repository_id', 'number'])]
class PullRequest implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: PullRequestId::class)]
    private PullRequestId $id;

    #[ORM\Column(type: TenantId::class)]
    private TenantId $tenantId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Repository $repository;

    #[ORM\Column]
    private int $number;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $title = null;

    /** The GitHub `state`: `open` or `closed` */
    #[ORM\Column(nullable: true)]
    private ?string $state = null;

    #[ORM\Column]
    private bool $merged = false;

    #[ORM\Column]
    private bool $draft = false;

    #[ORM\Column(type: CommitSha::class, nullable: true)]
    private ?CommitSha $headSha = null;

    /** Null when the fork of the head is deleted */
    #[ORM\Column(nullable: true)]
    private ?string $headRepositoryFullName = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $githubUpdatedAt = null;

    public function __construct(Repository $repository, int $number)
    {
        $this->id = PullRequestId::generate();
        $this->tenantId = $repository->getTenantId();
        $this->repository = $repository;
        $this->number = $number;
    }

    public function getTenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    /**
     * Null while the state is unknown.
     */
    public function getTitle(): ?string
    {
        return PullRequestState::Unknown === $this->shownState() ? null : $this->title;
    }

    public function shownState(): PullRequestState
    {
        return null === $this->state ? PullRequestState::Unknown : PullRequestState::ofGitHub($this->state, $this->merged, $this->draft);
    }

    /**
     * A state older than the stored one changes nothing, so deliveries and reads may arrive in any order.
     *
     * @param string $state The GitHub `state`: `open` or `closed`
     *
     * @return bool Whether the shown state or the title changed
     */
    public function record(string $title, string $state, bool $merged, bool $draft, CommitSha $headSha, ?string $headRepositoryFullName, \DateTimeImmutable $githubUpdatedAt): bool
    {
        if (null !== $this->githubUpdatedAt && $githubUpdatedAt < $this->githubUpdatedAt) {
            return false;
        }

        $shownBefore = [$this->shownState(), $this->getTitle()];
        $this->title = $title;
        $this->state = $state;
        $this->merged = $merged;
        $this->draft = $draft;
        $this->headSha = $headSha;
        $this->headRepositoryFullName = $headRepositoryFullName;
        $this->githubUpdatedAt = $githubUpdatedAt;

        return $shownBefore !== [$this->shownState(), $this->getTitle()];
    }
}
