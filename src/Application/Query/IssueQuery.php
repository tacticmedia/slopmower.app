<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Actor\Actor;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Identity\IssueEntryId;
use App\Domain\Identity\IssueId;
use App\Domain\Identity\ProjectId;
use App\Domain\Issue\CommentLabel;
use App\Domain\Issue\IssueEntryKind;
use App\Domain\Issue\IssueKey;
use App\Domain\Issue\IssueRules;
use App\Domain\Issue\IssueSection;
use App\Domain\Issue\IssueState;
use App\Domain\Issue\IssueStatus;
use App\Entity\Issue;
use App\Entity\IssueEntry;
use App\Entity\IssueLink;
use App\Entity\Project;
use App\Entity\Repository;
use App\Http\Cursor;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * The reads of the issue tracker (D116, D121): docs/specs/mcp.md sections 4.19 to 4.21 and 4.28, and P1, I1, I2, and I5
 * of docs/specs/rest-api-issues.md.
 */
final class IssueQuery
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * Sorted by key.
     *
     * @throws InvalidInput A malformed cursor
     */
    public function projects(int $limit, ?string $cursor): ProjectPage
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->select('project')
            ->from(Project::class, 'project')
            ->orderBy('project.key')
            ->setMaxResults($limit + 1);

        if (null !== $cursor) {
            $builder->andWhere('project.key > :afterKey')->setParameter('afterKey', Cursor::decode($cursor, [], 'string')[0]);
        }

        /** @var list<Project> $rows */
        $rows = $builder->getQuery()->getResult();
        $projects = array_slice($rows, 0, $limit);
        $repositoryNames = $this->repositoryNames($projects);

        return new ProjectPage(
            array_map(static fn (Project $project): ProjectView => new ProjectView($project->getKey(), $project->getName(), $project->getApprovalMode(), $repositoryNames[$project->getId()->toString()] ?? []), $projects),
            Cursor::next($rows, $limit, [], static fn (Project $project): array => [$project->getKey()]),
        );
    }

    /**
     * Sorted by updatedAt descending, then by ID descending.
     *
     * @throws NotFound
     * @throws InvalidInput A malformed cursor, or a cursor of other filters
     */
    public function list(string $projectKey, ?IssueStatus $status, int $limit, ?string $cursor): IssuePage
    {
        $filters = ['project' => $projectKey, 'status' => $status?->value];
        $builder = $this->entityManager->createQueryBuilder()
            ->select('issue')
            ->from(Issue::class, 'issue')
            ->where('issue.project = :project')
            ->setParameter('project', $this->project($projectKey)->getId(), ProjectId::class)
            ->orderBy('issue.updatedAt', \SortDirection::Descending)
            ->addOrderBy('issue.id', \SortDirection::Descending)
            ->setMaxResults($limit + 1);

        if (null !== $status) {
            $builder->andWhere('issue.status = :status')->setParameter('status', $status);
        }

        if (null !== $cursor) {
            [$beforeUpdatedAt, $beforeId] = Cursor::decode($cursor, $filters, 'time', 'ulid');
            $builder
                ->andWhere('issue.updatedAt < :beforeUpdatedAt OR (issue.updatedAt = :beforeUpdatedAt AND issue.id < :beforeId)')
                ->setParameter('beforeUpdatedAt', $beforeUpdatedAt, Types::DATETIME_IMMUTABLE)
                ->setParameter('beforeId', $beforeId, UlidType::NAME);
        }

        /** @var list<Issue> $rows */
        $rows = $builder->getQuery()->getResult();

        return new IssuePage(
            array_map(IssueSummaryView::of(...), array_slice($rows, 0, $limit)),
            Cursor::next($rows, $limit, $filters, static fn (Issue $issue): array => [$issue->getUpdatedAt()->getTimestamp(), $issue->getId()->toString()]),
        );
    }

    /**
     * The issue, its links, and its thread, each oldest first.
     *
     * @throws NotFound
     */
    public function get(IssueKey $key, Actor $actor): IssueDetail
    {
        $issue = $this->issue($key);
        /** @var list<IssueLink> $links */
        $links = $this->entityManager->createQueryBuilder()
            ->select('link', 'source', 'target')
            ->from(IssueLink::class, 'link')
            ->join('link.issue', 'source')
            ->join('link.target', 'target')
            ->where('link.issue = :issue OR link.target = :issue')
            ->setParameter('issue', $issue->getId(), IssueId::class)
            ->orderBy('link.createdAt')
            ->addOrderBy('source.id')
            ->addOrderBy('target.id')
            ->getQuery()
            ->getResult();
        /** @var list<IssueEntry> $thread */
        $thread = $this->entityManager->createQueryBuilder()
            ->select('entry')
            ->from(IssueEntry::class, 'entry')
            ->where('entry.issue = :issue')
            ->setParameter('issue', $issue->getId(), IssueId::class)
            ->orderBy('entry.id')
            ->getQuery()
            ->getResult();

        return new IssueDetail(
            $this->view($issue, $actor),
            array_map(static fn (IssueLink $link): array => ['issue' => IssueSummaryView::of($link->other($issue)), 'note' => $link->getNote(), 'createdBy' => $link->getCreatedBy(), 'createdAt' => $link->getCreatedAt()], $links),
            array_map(self::entry(...), $thread),
        );
    }

    /**
     * The comment entries of every issue of the project, newest first by entry ID.
     *
     * @throws NotFound
     * @throws InvalidInput A malformed cursor, or a cursor of other filters
     */
    public function comments(string $projectKey, ?CommentLabel $label, int $limit, ?string $cursor): IssueCommentPage
    {
        $filters = ['project' => $projectKey, 'label' => $label?->value];
        $builder = $this->entityManager->createQueryBuilder()
            ->select('entry', 'issue')
            ->from(IssueEntry::class, 'entry')
            ->join('entry.issue', 'issue')
            ->where('issue.project = :project')
            ->andWhere('entry.kind = :kind')
            ->setParameter('project', $this->project($projectKey)->getId(), ProjectId::class)
            ->setParameter('kind', IssueEntryKind::Comment)
            ->orderBy('entry.id', \SortDirection::Descending)
            ->setMaxResults($limit + 1);

        if (null !== $label) {
            // A comment stored without a label counts as comment, as IssueEntry::getLabel() reads it.
            $builder->andWhere('COALESCE(entry.label, :unlabelled) = :label')->setParameter('unlabelled', CommentLabel::Comment)->setParameter('label', $label);
        }

        if (null !== $cursor) {
            $builder->andWhere('entry.id < :beforeId')->setParameter('beforeId', Cursor::decode($cursor, $filters, 'ulid')[0], UlidType::NAME);
        }

        /** @var list<IssueEntry> $rows */
        $rows = $builder->getQuery()->getResult();

        return new IssueCommentPage(
            array_map(static fn (IssueEntry $entry): array => ['id' => $entry->getId(), 'issue' => IssueSummaryView::of($entry->getIssue()), 'label' => $entry->getLabel(), 'actor' => $entry->getActor(), 'createdAt' => $entry->getCreatedAt(), 'body' => $entry->getBody()], array_slice($rows, 0, $limit)),
            Cursor::next($rows, $limit, $filters, static fn (IssueEntry $entry): array => [$entry->getId()->toString()]),
        );
    }

    /**
     * The issue of every answer, with the statuses that $actor can set.
     */
    public function view(Issue $issue, Actor $actor): IssueView
    {
        $project = $issue->getProject();
        $approval = $issue->getPlanApproval();
        $state = $this->state($issue);

        return new IssueView(
            $issue->getKey(),
            $issue->getTitle(),
            $issue->getStatus(),
            $issue->getSection(IssueSection::Problem),
            $issue->getSection(IssueSection::Plan),
            $issue->getSection(IssueSection::Result),
            $issue->getSection(IssueSection::FollowUps),
            ['key' => $project->getKey(), 'name' => $project->getName(), 'approvalMode' => $project->getApprovalMode()],
            $state->planRevision,
            null === $approval ? null : ['approvedBy' => $approval->getActor(), 'approvedAt' => $approval->getCreatedAt(), 'reviewer' => $approval->get('reviewer'), 'reviewNote' => $approval->get('reviewNote')],
            IssueRules::allowedStatuses($state, $project->getApprovalMode(), $actor->kind),
            $issue->getCreatedBy(),
            $issue->getCreatedAt(),
            $issue->getUpdatedAt(),
        );
    }

    /**
     * What {@see IssueRules} reads, from the stored rows.
     */
    public function state(Issue $issue): IssueState
    {
        $approval = $issue->getPlanApproval();

        return new IssueState(
            $issue->getStatus(),
            $issue->getSection(IssueSection::Problem),
            $issue->getSection(IssueSection::Plan),
            $issue->getSection(IssueSection::Result),
            $this->planRevision($issue)?->toString(),
            $approval?->getActor()->kind,
            $approval?->get('reviewer'),
            $approval?->get('planRevision'),
        );
    }

    /**
     * @param LockMode $lock PESSIMISTIC_WRITE inside a transaction locks the row until the commit
     *
     * @throws NotFound
     */
    public function project(string $key, LockMode $lock = LockMode::NONE): Project
    {
        return $this->entityManager->createQueryBuilder()
            ->select('project')
            ->from(Project::class, 'project')
            ->where('project.key = :key')
            ->setParameter('key', $key)
            ->getQuery()
            ->setLockMode($lock)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult() ?? throw new NotFound();
    }

    /**
     * The project is not part of the locked query, so the lock takes only the issue row.
     *
     * @param LockMode $lock PESSIMISTIC_WRITE inside a transaction locks the row until the commit
     *
     * @throws NotFound
     */
    public function issue(IssueKey $key, LockMode $lock = LockMode::NONE): Issue
    {
        return $this->entityManager->createQueryBuilder()
            ->select('issue')
            ->from(Issue::class, 'issue')
            ->where('issue.project = :project')
            ->andWhere('issue.number = :number')
            ->setParameter('project', $this->project($key->projectKey)->getId(), ProjectId::class)
            ->setParameter('number', $key->number)
            ->getQuery()
            ->setLockMode($lock)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult() ?? throw new NotFound();
    }

    /**
     * The ID of the newest section_changed entry of the plan.
     */
    private function planRevision(Issue $issue): ?IssueEntryId
    {
        return $this->entityManager->createQueryBuilder()
            ->select('entry')
            ->from(IssueEntry::class, 'entry')
            ->where('entry.issue = :issue')
            ->andWhere('entry.kind = :kind')
            ->andWhere("JSONB_PATH_TEXT(entry.data, 'section') = :section")
            ->setParameter('issue', $issue->getId(), IssueId::class)
            ->setParameter('kind', IssueEntryKind::SectionChanged)
            ->setParameter('section', IssueSection::Plan->value)
            ->orderBy('entry.id', \SortDirection::Descending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
            ?->getId();
    }

    /**
     * @param list<Project> $projects
     *
     * @return array<string, list<string>> The names of the repositories of each project, in name order, by project ID
     */
    private function repositoryNames(array $projects): array
    {
        /** @var list<Repository> $repositories */
        $repositories = [] === $projects ? [] : $this->entityManager->createQueryBuilder()
            ->select('repository')
            ->from(Repository::class, 'repository')
            ->where('repository.project IN (:projects)')
            ->setParameter('projects', array_map(static fn (Project $project): string => $project->getId()->toRfc4122(), $projects), ArrayParameterType::STRING)
            ->orderBy('LOWER(repository.name)')
            ->addOrderBy('repository.id')
            ->getQuery()
            ->getResult();
        $names = [];

        foreach ($repositories as $repository) {
            $names[(string) $repository->getProject()?->getId()][] = $repository->getName()->toString();
        }

        return $names;
    }

    /**
     * @return array<string, mixed> An IssueEntry of issue.schema.json
     */
    private static function entry(IssueEntry $entry): array
    {
        return [
            'id' => $entry->getId(),
            'kind' => $entry->getKind(),
            'label' => $entry->getLabel(),
            'actor' => $entry->getActor(),
            'createdAt' => $entry->getCreatedAt(),
            // The body of a section_changed entry is a revision of the section, which the thread leaves out.
            'body' => IssueEntryKind::Comment === $entry->getKind() ? $entry->getBody() : null,
            'section' => $entry->get('section'),
            'from' => $entry->get('from'),
            'to' => $entry->get('to'),
            'note' => $entry->get('note'),
            'reviewer' => $entry->get('reviewer'),
            'reviewNote' => $entry->get('reviewNote'),
            'planRevision' => $entry->get('planRevision'),
        ];
    }
}
