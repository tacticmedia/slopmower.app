<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Actor\Actor;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Identity\IssueEntryId;
use App\Domain\Identity\IssueId;
use App\Domain\Identity\ProjectId;
use App\Domain\Identity\UserId;
use App\Domain\Issue\CommentLabel;
use App\Domain\Issue\IssueCategory;
use App\Domain\Issue\IssueClaim;
use App\Domain\Issue\IssueEntryKind;
use App\Domain\Issue\IssueKey;
use App\Domain\Issue\IssuePartyKind;
use App\Domain\Issue\IssueRules;
use App\Domain\Issue\IssueSection;
use App\Domain\Issue\IssueState;
use App\Domain\Issue\IssueStatus;
use App\Domain\Issue\WaitingFor;
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
use Doctrine\ORM\QueryBuilder;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * The reads of the issue tracker (D116, D121): docs/specs/mcp.md sections 4.19 to 4.21, 4.28, and 4.29, and P1, I1, I2,
 * I5, and I10 of docs/specs/rest-api-issues.md. The writes answer with {@see projectView()} and {@see view()}.
 */
final class IssueQuery
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
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
            array_map(static fn (Project $project): ProjectView => new ProjectView($project->getKey(), $project->getName(), $project->getApprovalMode(), $project->getApprovalGuidelines(), $repositoryNames[$project->getId()->toString()] ?? []), $projects),
            Cursor::next($rows, $limit, [], static fn (Project $project): array => [$project->getKey()]),
        );
    }

    /**
     * The project of the answers of P2 and P3, as an item of {@see projects()}.
     */
    public function projectView(Project $project): ProjectView
    {
        return new ProjectView($project->getKey(), $project->getName(), $project->getApprovalMode(), $project->getApprovalGuidelines(), $this->repositoryNames([$project])[$project->getId()->toString()] ?? []);
    }

    /**
     * I1 (D137). One activity filter at most, counted back from the time of the first page, which the cursor keeps.
     *
     * @throws NotFound
     * @throws InvalidInput A second activity filter, a malformed cursor, or a cursor of another sort or other filters
     */
    public function list(string $projectKey, ?IssueStatus $status, ?IssueCategory $category, int $limit, ?string $cursor, ?IssuePartyKind $waitingForKind = null, IssueSort $sort = IssueSort::Rank, ?int $activeWithinHours = null, ?int $idleForHours = null, bool $noActivity = false): IssuePage
    {
        $activityFilters = array_filter(['activeWithinHours' => $activeWithinHours, 'idleForHours' => $idleForHours, 'noActivity' => $noActivity ?: null], static fn (int|bool|null $value): bool => null !== $value);

        $secondFilter = array_keys($activityFilters)[1] ?? null;

        if (null !== $secondFilter) {
            throw InvalidInput::field($secondFilter, 'Give at most one of activeWithinHours, idleForHours, and noActivity.');
        }

        $filters = ['project' => $projectKey, 'sort' => $sort->value, 'status' => $status?->value, 'category' => $category?->value, 'waitingForKind' => $waitingForKind?->value] + $activityFilters;
        $builder = $this->entityManager->createQueryBuilder()
            ->select('issue')
            ->from(Issue::class, 'issue')
            ->where('issue.project = :project')
            ->setParameter('project', $this->project($projectKey)->getId(), ProjectId::class)
            ->setMaxResults($limit + 1);
        $after = null === $cursor ? null : Cursor::decode($cursor, $filters, 'time', ...(IssueSort::Rank === $sort ? ['string', 'int'] : ['time', 'ulid']));
        $listedAt = $after[0] ?? new \DateTimeImmutable('@'.$this->clock->now()->getTimestamp());
        \assert($listedAt instanceof \DateTimeImmutable);

        if (IssueSort::Rank === $sort) {
            // The enum values sort bug before change.
            $builder->orderBy('issue.category')->addOrderBy('issue.rank');

            if (null === $status) {
                $builder->andWhere('issue.status NOT IN (:ended)')->setParameter('ended', IssueStatus::ended());
            }

            if (null !== $after) {
                $builder
                    ->andWhere('issue.category > :afterCategory OR (issue.category = :afterCategory AND issue.rank > :afterRank)')
                    ->setParameter('afterCategory', IssueCategory::tryFrom($after[1]) ?? throw Cursor::malformed())
                    ->setParameter('afterRank', $after[2]);
            }
        } else {
            $builder->orderBy('issue.updatedAt', \SortDirection::Descending)->addOrderBy('issue.id', \SortDirection::Descending);

            if (null !== $after) {
                $builder
                    ->andWhere('issue.updatedAt < :beforeUpdatedAt OR (issue.updatedAt = :beforeUpdatedAt AND issue.id < :beforeId)')
                    ->setParameter('beforeUpdatedAt', $after[1], Types::DATETIME_IMMUTABLE)
                    ->setParameter('beforeId', $after[2], UlidType::NAME);
            }
        }

        if (null !== $status) {
            $builder->andWhere('issue.status = :status')->setParameter('status', $status);
        }

        if (null !== $category) {
            $builder->andWhere('issue.category = :category')->setParameter('category', $category);
        }

        if (null !== $waitingForKind) {
            self::whereWaitsFor($builder, $waitingForKind);
        }

        if (null !== $activeWithinHours) {
            $builder->andWhere('issue.lastActivityAt >= :cutoff')->setParameter('cutoff', $listedAt->modify("-{$activeWithinHours} hours"), Types::DATETIME_IMMUTABLE);
        }

        if (null !== $idleForHours) {
            // An issue without activity counts from its creation, so a new issue is not idle.
            $builder->andWhere('COALESCE(issue.lastActivityAt, issue.createdAt) < :cutoff')->setParameter('cutoff', $listedAt->modify("-{$idleForHours} hours"), Types::DATETIME_IMMUTABLE);
        }

        if ($noActivity) {
            $builder->andWhere('issue.lastActivityAt IS NULL');
        }

        /** @var list<Issue> $rows */
        $rows = $builder->getQuery()->getResult();
        $now = $this->clock->now();
        $sortKey = IssueSort::Rank === $sort
            ? static fn (Issue $issue): array => [$listedAt->getTimestamp(), $issue->getCategory()->value, $issue->getRank()]
            : static fn (Issue $issue): array => [$listedAt->getTimestamp(), $issue->getUpdatedAt()->getTimestamp(), $issue->getId()->toString()];

        return new IssuePage(
            array_map(static fn (Issue $issue): IssueSummaryView => IssueSummaryView::of($issue, $now), array_slice($rows, 0, $limit)),
            Cursor::next($rows, $limit, $filters, $sortKey),
        );
    }

    /**
     * The issue, its links, and its thread, each oldest first.
     *
     * @param bool $history False: no replaced entry, and no text in a section_changed entry, because the issue has it (D123)
     *
     * @throws NotFound
     */
    public function get(IssueKey $key, Actor $actor, bool $history = false): IssueDetail
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
        [$replaces, $replacedBy] = self::replacements($thread);
        $entries = [];

        foreach ($thread as $entry) {
            $id = $entry->getId()->toString();

            if ($history || !isset($replacedBy[$id])) {
                $entries[] = self::entry($entry, $history, $replaces[$id] ?? null, $replacedBy[$id] ?? null);
            }
        }

        $now = $this->clock->now();

        return new IssueDetail(
            $this->view($issue, $actor),
            array_map(static fn (IssueLink $link): array => ['issue' => IssueSummaryView::of($link->other($issue), $now), 'kind' => $link->kindFor($issue), 'note' => $link->getNote(), 'createdBy' => $link->getCreatedBy(), 'createdAt' => $link->getCreatedAt()], $links),
            $entries,
        );
    }

    /**
     * The comment entries of every issue of the project, or of one issue, that no comment replaced, newest first by entry ID.
     *
     * @throws NotFound
     * @throws InvalidInput An issue of another project, a malformed cursor, or a cursor of other filters
     */
    public function comments(string $projectKey, ?IssueKey $issue, ?CommentLabel $label, int $limit, ?string $cursor): IssueCommentPage
    {
        if (null !== $issue && $issue->projectKey !== $projectKey) {
            throw InvalidInput::field('issue', "Must be an issue of the project {$projectKey}.");
        }

        $filters = ['project' => $projectKey, 'issue' => $issue?->toString(), 'label' => $label?->value];
        $builder = $this->entityManager->createQueryBuilder()
            ->select('entry', 'issue')
            ->from(IssueEntry::class, 'entry')
            ->join('entry.issue', 'issue')
            ->where('issue.project = :project')
            ->andWhere('entry.kind = :kind')
            ->andWhere('NOT EXISTS (SELECT newer.id FROM '.IssueEntry::class.' newer WHERE newer.replaces = entry)')
            ->setParameter('project', $this->project($projectKey)->getId(), ProjectId::class)
            ->setParameter('kind', IssueEntryKind::Comment)
            ->orderBy('entry.id', \SortDirection::Descending)
            ->setMaxResults($limit + 1);

        if (null !== $issue) {
            $builder->andWhere('entry.issue = :issue')->setParameter('issue', $this->issue($issue)->getId(), IssueId::class);
        }

        if (null !== $label) {
            // A comment stored without a label counts as comment, as IssueEntry::getLabel() reads it.
            $builder->andWhere('COALESCE(entry.label, :unlabelled) = :label')->setParameter('unlabelled', CommentLabel::Comment)->setParameter('label', $label);
        }

        if (null !== $cursor) {
            $builder->andWhere('entry.id < :beforeId')->setParameter('beforeId', Cursor::decode($cursor, $filters, 'ulid')[0], UlidType::NAME);
        }

        /** @var list<IssueEntry> $rows */
        $rows = $builder->getQuery()->getResult();
        $now = $this->clock->now();

        return new IssueCommentPage(
            array_map(static fn (IssueEntry $entry): array => ['id' => $entry->getId(), 'issue' => IssueSummaryView::of($entry->getIssue(), $now), 'label' => $entry->getLabel(), 'actor' => $entry->getActor(), 'createdAt' => $entry->getCreatedAt(), 'body' => $entry->getBody(), 'questions' => $entry->getQuestions()], array_slice($rows, 0, $limit)),
            Cursor::next($rows, $limit, $filters, static fn (IssueEntry $entry): array => [$entry->getId()->toString()]),
        );
    }

    /**
     * I10 (D128). The newest number comes first, and every entry up to it has committed, because a write numbers its
     * entries under the row lock of the project. `limit` counts the entries that it reads, also the replaced ones that
     * it leaves out.
     *
     * @param int|null $cursor Null for the issues in work and the newest number
     *
     * @throws NotFound
     * @throws InvalidInput A cursor ahead of the server
     */
    public function updates(string $projectKey, Actor $caller, ?int $cursor, int $limit): IssueUpdatesPage
    {
        $project = $this->project($projectKey);
        $newest = $project->getEntrySeq();

        if (null === $cursor) {
            /** @var list<Issue> $inWork */
            $inWork = $this->entityManager->createQueryBuilder()
                ->select('issue')
                ->from(Issue::class, 'issue')
                ->where('issue.project = :project')
                ->andWhere('issue.status IN (:inWork)')
                ->setParameter('project', $project->getId(), ProjectId::class)
                ->setParameter('inWork', [IssueStatus::Todo, IssueStatus::InProgress, IssueStatus::InReview, IssueStatus::Approved])
                ->orderBy('issue.updatedAt', \SortDirection::Descending)
                ->addOrderBy('issue.id', \SortDirection::Descending)
                ->setMaxResults($limit)
                ->getQuery()
                ->getResult();

            $now = $this->clock->now();

            return new IssueUpdatesPage($newest, false, array_map(static fn (Issue $issue): IssueSummaryView => IssueSummaryView::of($issue, $now), $inWork), []);
        }

        if ($cursor > $newest) {
            throw InvalidInput::field('cursor', "Is ahead of the server, whose newest entry of the project is {$newest}.");
        }

        $builder = $this->entityManager->createQueryBuilder()
            ->select('entry', 'issue')
            ->from(IssueEntry::class, 'entry')
            ->join('entry.issue', 'issue')
            ->where('issue.project = :project')
            ->andWhere('entry.seq > :after')
            ->andWhere('entry.seq <= :newest')
            ->setParameter('project', $project->getId(), ProjectId::class)
            ->setParameter('after', $cursor)
            ->setParameter('newest', $newest)
            ->setParameter('callerKind', $caller->kind->value)
            ->setParameter('callerUserId', $caller->userId ?? throw new \LogicException('The system reads no updates.'), UserId::class)
            ->orderBy('entry.seq')
            ->setMaxResults($limit + 1);
        // A user has no agent name, so the kind and the user say who it is.
        $sameActor = 'entry.actor.kind = :callerKind AND entry.actor.userId = :callerUserId';

        if (null !== $caller->agentName) {
            $sameActor .= ' AND entry.actor.agentName = :callerAgentName';
            $builder->setParameter('callerAgentName', $caller->agentName);
        }

        /** @var list<IssueEntry> $rows */
        $rows = $builder->andWhere("NOT ({$sameActor})")->getQuery()->getResult();
        $hasMore = count($rows) > $limit;
        $read = array_slice($rows, 0, $limit);
        $versions = $this->versions(array_map(static fn (IssueEntry $entry): Issue => $entry->getIssue(), $read));
        $entries = [];
        $now = $this->clock->now();

        foreach ($read as $entry) {
            [$replaces, $replacedBy] = $versions[$entry->getId()->toString()];

            if (null === $replacedBy) {
                $entries[] = ['issue' => IssueSummaryView::of($entry->getIssue(), $now), 'entry' => self::entry($entry, false, $replaces, null)];
            }
        }

        $next = $hasMore ? array_last($read)?->getSeq() : null;

        return new IssueUpdatesPage($next ?? $newest, $hasMore, [], $entries);
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
            $issue->getCategory(),
            $issue->getStatus(),
            $issue->getApprovalMode(),
            IssueClaim::held($issue->getClaim(), $this->clock->now()),
            $issue->getRequester(),
            $issue->getAssignee(),
            $issue->getWaitingFor(),
            $issue->getSection(IssueSection::Problem),
            $issue->getSection(IssueSection::AcceptanceCriteria),
            $issue->getSection(IssueSection::Plan),
            $issue->getSection(IssueSection::Result),
            $issue->getSection(IssueSection::FollowUps),
            $issue->getSection(IssueSection::Notes),
            ['key' => $project->getKey(), 'name' => $project->getName(), 'approvalMode' => $project->getApprovalMode()],
            $state->planRevision,
            null === $approval ? null : ['approvedBy' => $approval->getActor(), 'approvedAt' => $approval->getCreatedAt(), 'reviewer' => $approval->get('reviewer'), 'reviewNote' => $approval->get('reviewNote')],
            IssueRules::allowedStatuses($state, $issue->getApprovalMode(), $actor->kind),
            $issue->getCreatedBy(),
            $issue->getCreatedAt(),
            $issue->getUpdatedAt(),
            $issue->getLastActivityAt(),
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
            $issue->getWaitingFor(),
            $issue->getRequester()->kind,
            $issue->getSection(IssueSection::AcceptanceCriteria),
        );
    }

    /**
     * Narrows $builder, whose root alias is `issue`, to the issues whose waitingFor names a party of $kind (D134).
     */
    public static function whereWaitsFor(QueryBuilder $builder, IssuePartyKind $kind): QueryBuilder
    {
        return $builder
            ->andWhere('(issue.waitingFor = :requesterRole AND issue.requester.kind = :waitingKind) OR (issue.waitingFor = :assigneeRole AND issue.assignee.kind = :waitingKind)')
            ->setParameter('requesterRole', WaitingFor::Requester)
            ->setParameter('assigneeRole', WaitingFor::Assignee)
            ->setParameter('waitingKind', $kind);
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
     * {@see replacements()} of the threads of the issues, read now.
     *
     * @param list<Issue> $issues
     *
     * @return array<string, array{?IssueEntryId, ?IssueEntryId}> By entry ID: the entry that it replaced, and the entry that replaced it
     */
    private function versions(array $issues): array
    {
        /** @var list<IssueEntry> $entries */
        $entries = [] === $issues ? [] : $this->entityManager->createQueryBuilder()
            ->select('entry')
            ->from(IssueEntry::class, 'entry')
            ->where('entry.issue IN (:issues)')
            ->setParameter('issues', array_values(array_unique(array_map(static fn (Issue $issue): string => $issue->getId()->toRfc4122(), $issues))), ArrayParameterType::STRING)
            ->orderBy('entry.id')
            ->getQuery()
            ->getResult();
        $threads = [];

        foreach ($entries as $entry) {
            $threads[$entry->getIssue()->getId()->toString()][] = $entry;
        }

        $pairs = [];

        foreach ($threads as $thread) {
            [$replaces, $replacedBy] = self::replacements($thread);

            foreach ($thread as $entry) {
                $id = $entry->getId()->toString();
                $pairs[$id] = [$replaces[$id] ?? null, $replacedBy[$id] ?? null];
            }
        }

        return $pairs;
    }

    /**
     * Who replaced whom (D123): the next entry of a section replaces the one before it, a comment replaces the comment
     * that it names, and the plan version after an approved one replaces that approval.
     *
     * @param list<IssueEntry> $thread Oldest first
     *
     * @return array{array<string, IssueEntryId>, array<string, IssueEntryId>} By entry ID: the entry that it replaced, and the entry that replaced it
     */
    private static function replacements(array $thread): array
    {
        $replaces = $replacedBy = $newestOfSection = [];

        foreach ($thread as $entry) {
            $section = $entry->get('section');
            $replaced = null === $section ? $entry->getReplaces()?->getId() : $newestOfSection[$section] ?? null;

            if (null !== $section) {
                $newestOfSection[$section] = $entry->getId();
            }

            if (null !== $replaced) {
                $replaces[$entry->getId()->toString()] = $replaced;
                $replacedBy[$replaced->toString()] = $entry->getId();
            }
        }

        foreach ($thread as $entry) {
            $nextPlan = IssueEntryKind::PlanApproved === $entry->getKind() ? $replacedBy[$entry->get('planRevision') ?? ''] ?? null : null;

            if (null !== $nextPlan) {
                $replacedBy[$entry->getId()->toString()] = $nextPlan;
            }
        }

        return [$replaces, $replacedBy];
    }

    /**
     * @return array<string, mixed> An IssueEntry of issue.schema.json
     */
    private static function entry(IssueEntry $entry, bool $history, ?IssueEntryId $replaces, ?IssueEntryId $replacedBy): array
    {
        return [
            'id' => $entry->getId(),
            'kind' => $entry->getKind(),
            'label' => $entry->getLabel(),
            'actor' => $entry->getActor(),
            'createdAt' => $entry->getCreatedAt(),
            'body' => $history || IssueEntryKind::Comment === $entry->getKind() ? $entry->getBody() : null,
            'questions' => $entry->getQuestions(),
            'section' => $entry->get('section'),
            'from' => $entry->get('from'),
            'to' => $entry->get('to'),
            'waitingFor' => $entry->get('waitingFor'),
            'note' => $entry->get('note'),
            'reviewer' => $entry->get('reviewer'),
            'reviewNote' => $entry->get('reviewNote'),
            'planRevision' => $entry->get('planRevision'),
            'replaces' => $replaces,
            'replacedBy' => $replacedBy,
        ];
    }
}
