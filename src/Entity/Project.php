<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Identity\ProjectId;
use App\Domain\Identity\TenantId;
use App\Domain\Issue\ApprovalMode;
use App\Domain\Issue\IssueKey;
use Doctrine\ORM\Mapping as ORM;

/**
 * A project of the issue tracker (D116). Its key never changes, because the issue keys hold it.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_project_key', columns: ['tenant_id', 'key'])]
class Project implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: ProjectId::class)]
    private ProjectId $id;

    #[ORM\Column(type: TenantId::class)]
    private TenantId $tenantId;

    #[ORM\Column(length: 10)]
    private string $key;

    #[ORM\Column]
    private string $name;

    #[ORM\Column(enumType: ApprovalMode::class)]
    private ApprovalMode $approvalMode;

    #[ORM\Column]
    private int $nextIssueNumber = 1;

    /** The number of the newest entry of the threads of its issues (D128). */
    #[ORM\Column(options: ['default' => 0])]
    private int $entrySeq = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(TenantId $tenantId, string $key, string $name, ApprovalMode $approvalMode, \DateTimeImmutable $createdAt)
    {
        if (1 !== preg_match(IssueKey::PROJECT_KEY_PATTERN, $key)) {
            throw new \InvalidArgumentException("The project key {$key} does not have 2 to 10 upper-case letters.");
        }

        $this->id = ProjectId::generate();
        $this->tenantId = $tenantId;
        $this->key = $key;
        $this->name = $name;
        $this->approvalMode = $approvalMode;
        $this->createdAt = $createdAt;
    }

    public function getId(): ProjectId
    {
        return $this->id;
    }

    public function getTenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getApprovalMode(): ApprovalMode
    {
        return $this->approvalMode;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function setApprovalMode(ApprovalMode $approvalMode): void
    {
        $this->approvalMode = $approvalMode;
    }

    /**
     * Call it under the row lock of the project, so two issues never get one number.
     */
    public function takeIssueNumber(): int
    {
        return $this->nextIssueNumber++;
    }

    /**
     * {@see IssueEntry} takes it. Call it under the row lock of the project, so the numbers follow the commit order.
     */
    public function takeEntrySeq(): int
    {
        return ++$this->entrySeq;
    }

    public function getEntrySeq(): int
    {
        return $this->entrySeq;
    }
}
