<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\FetchRepository;
use App\Application\IssueCommands;
use App\Application\Query\IssueCommits;
use App\Application\Query\IssueGraph;
use App\Application\Query\IssueQuery;
use App\Application\Query\IssueSummaryView;
use App\Controller\Api\Input\ApprovePlanInput;
use App\Controller\Api\Input\CreateIssueInput;
use App\Controller\Api\Input\IssueCommentInput;
use App\Controller\Api\Input\IssueCommentListQuery;
use App\Controller\Api\Input\IssueDetailQuery;
use App\Controller\Api\Input\IssueListQuery;
use App\Controller\Api\Input\IssueStatusInput;
use App\Controller\Api\Input\IssueUpdatesQuery;
use App\Controller\Api\Input\RankIssueInput;
use App\Controller\Api\Input\UpdateIssueInput;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Error\RepositoryUnavailable;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\IssueEntryId;
use App\Domain\Identity\ProjectId;
use App\Domain\Issue\CommentLabel;
use App\Domain\Issue\IssueKey;
use App\Domain\Issue\IssueLinkKind;
use App\Domain\Issue\IssuePartyKind;
use App\Domain\Issue\IssueStatus;
use App\Domain\Repository\CodeAccess;
use App\Domain\Repository\RepositorySource;
use App\Domain\Repository\SyncState;
use App\Entity\Issue;
use App\Entity\IssueLink;
use App\Entity\Project;
use App\Entity\Repository;
use App\Entity\User;
use App\Git\GitCodeReader;
use App\Git\GitFailed;
use App\Git\LogCommit;
use App\Security\CurrentActor;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * I1 to I13 of docs/specs/rest-api-issues.md. A path key that the SQL filters hide resolves to 404.
 */
#[Route('/api/v1', name: 'api_issue_', requirements: ['projectKey' => IssueKey::PROJECT_KEY_REQUIREMENT, 'issueKey' => IssueKey::REQUIREMENT])]
final class IssueController extends AbstractController
{
    private const int COMMITS_PER_REPOSITORY = 100;

    public function __construct(
        private readonly IssueQuery $issues,
        private readonly IssueCommands $commands,
        private readonly CurrentActor $currentActor,
    ) {
    }

    #[Route('/projects/{projectKey}/issues', name: 'list', methods: ['GET'])]
    public function list(
        #[MapEntity(mapping: ['projectKey' => 'key'])] Project $project,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        IssueListQuery $query = new IssueListQuery(),
    ): JsonResponse {
        return $this->json($this->issues->list($project->getKey(), $query->status, $query->category, $query->limit, $query->cursor, $query->waitingForKind, $query->sort, $query->activeWithinHours, $query->idleForHours, $query->noActivity));
    }

    #[Route('/issues/{issueKey}', name: 'get', methods: ['GET'])]
    public function get(
        #[ValueResolver(IssueKeyResolver::class)] Issue $issue,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        IssueDetailQuery $query = new IssueDetailQuery(),
    ): JsonResponse {
        return $this->json($this->issues->get($issue->getKey(), $this->currentActor->get(), $query->history));
    }

    #[Route('/issues/{issueKey}/comments', name: 'comment', methods: ['POST'])]
    public function comment(
        #[ValueResolver(IssueKeyResolver::class)] Issue $issue,
        #[MapRequestPayload(acceptFormat: 'json')] IssueCommentInput $input,
    ): JsonResponse {
        \assert(null !== $input->body);
        $actor = $this->currentActor->get();

        $replaces = null === $input->replaces ? null : IssueEntryId::fromString($input->replaces, 'replaces');

        return $this->json($this->issues->view($this->commands->comment($issue->getKey(), $input->body, $input->label ?? CommentLabel::Comment, $actor, $input->session, $replaces, $input->questions ?? [], $input->dismiss, $input->waitingFor), $actor), Response::HTTP_CREATED);
    }

    #[Route('/projects/{projectKey}/comments', name: 'comments', methods: ['GET'])]
    public function comments(
        #[MapEntity(mapping: ['projectKey' => 'key'])] Project $project,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        IssueCommentListQuery $query = new IssueCommentListQuery(),
    ): JsonResponse {
        return $this->json($this->issues->comments($project->getKey(), null === $query->issue ? null : IssueKey::fromString($query->issue, 'issue'), $query->label, $query->limit, $query->cursor));
    }

    /**
     * The SQL filters leave out a link with an issue that the caller cannot see.
     */
    #[Route('/projects/{projectKey}/graph', name: 'graph', methods: ['GET'])]
    public function graph(#[MapEntity(mapping: ['projectKey' => 'key'])] Project $project, EntityManagerInterface $entityManager, ClockInterface $clock): JsonResponse
    {
        $ended = IssueStatus::ended();
        /** @var list<IssueLink> $dependencies */
        $dependencies = $entityManager->createQueryBuilder()
            ->select('link', 'waiting', 'blocker')
            ->from(IssueLink::class, 'link')
            ->join('link.issue', 'waiting')
            ->join('link.target', 'blocker')
            ->where('link.kind = :blockedBy')
            ->andWhere('waiting.project = :project OR blocker.project = :project')
            ->andWhere('waiting.status NOT IN (:ended)')
            ->andWhere('blocker.status NOT IN (:ended)')
            ->setParameter('blockedBy', IssueLinkKind::BlockedBy)
            ->setParameter('project', $project->getId(), ProjectId::class)
            ->setParameter('ended', $ended)
            ->getQuery()
            ->getResult();
        $ofProject = static fn (string $select, string $operator, array $statuses): Query => $entityManager->createQueryBuilder()
            ->select($select)
            ->from(Issue::class, 'issue')
            ->where('issue.project = :project')
            ->andWhere("issue.status {$operator} (:statuses)")
            ->setParameter('project', $project->getId(), ProjectId::class)
            ->setParameter('statuses', $statuses)
            ->getQuery();
        /** @var list<Issue> $nodes A draft is a node only with a dependency, because it is an idea that waits (D134). */
        $nodes = IssueQuery::whereWaitsFor($entityManager->createQueryBuilder()->select('issue')->from(Issue::class, 'issue'), IssuePartyKind::User)
            ->andWhere('issue.project = :project')
            ->andWhere('issue.status <> :draft')
            ->setParameter('project', $project->getId(), ProjectId::class)
            ->setParameter('draft', IssueStatus::Draft)
            ->getQuery()
            ->getResult();
        $issues = [];

        foreach ($dependencies as $link) {
            array_push($nodes, $link->getIssue(), $link->getTarget());
        }

        foreach ($nodes as $issue) {
            $issues[$issue->getKey()->toString()] = $issue;
        }

        $order = static fn (Issue $issue): array => [$issue->getKey()->projectKey, $issue->getKey()->number];
        usort($issues, static fn (Issue $first, Issue $second): int => $order($first) <=> $order($second));
        usort($dependencies, static fn (IssueLink $first, IssueLink $second): int => [$order($first->getIssue()), $order($first->getTarget())] <=> [$order($second->getIssue()), $order($second->getTarget())]);
        $now = $clock->now();

        return $this->json(new IssueGraph(
            array_map(static fn (Issue $issue): IssueSummaryView => IssueSummaryView::of($issue, $now), $issues),
            array_map(static fn (IssueLink $link): array => ['issue' => $link->getIssue()->getKey(), 'blockedBy' => $link->getTarget()->getKey()], $dependencies),
            max(0, (int) $ofProject('COUNT(issue.id)', 'NOT IN', $ended)->getSingleScalarResult() - \count(array_filter($issues, static fn (Issue $issue): bool => $issue->getProject() === $project))),
        ));
    }

    #[Route('/projects/{projectKey}/updates', name: 'updates', methods: ['GET'])]
    public function updates(
        #[MapEntity(mapping: ['projectKey' => 'key'])] Project $project,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        IssueUpdatesQuery $query = new IssueUpdatesQuery(),
    ): JsonResponse {
        return $this->json($this->issues->updates($project->getKey(), $this->currentActor->get(), $query->cursor, $query->limit));
    }

    /**
     * The approval of a user, with no reviewer and no review note.
     */
    #[Route('/issues/{issueKey}/plan-approval', name: 'approve_plan', methods: ['POST'])]
    #[IsGranted(User::ROLE, message: CurrentActor::SESSION_ONLY)]
    public function approvePlan(
        #[ValueResolver(IssueKeyResolver::class)] Issue $issue,
        #[MapRequestPayload(acceptFormat: 'json')] ApprovePlanInput $input,
    ): JsonResponse {
        \assert(null !== $input->planRevision);
        $actor = $this->currentActor->get();
        $approved = $this->commands->approvePlan($issue->getKey(), IssueEntryId::fromString($input->planRevision, 'planRevision'), null, null, $actor, null);

        return $this->json($this->issues->view($approved, $actor));
    }

    #[Route('/projects/{projectKey}/issues', name: 'create', methods: ['POST'])]
    public function create(
        #[MapEntity(mapping: ['projectKey' => 'key'])] Project $project,
        #[MapRequestPayload(acceptFormat: 'json')] CreateIssueInput $input,
    ): JsonResponse {
        \assert(null !== $input->title && null !== $input->category);
        $actor = $this->currentActor->get();
        $issue = $this->commands->create($project->getKey(), $input->title, $input->category, $input->problem, $input->status ?? IssueStatus::Draft, $actor, $input->approval, $input->requester, $input->assignee, $input->priority);

        return $this->json($this->issues->view($issue, $actor), Response::HTTP_CREATED, ['Location' => $this->generateUrl('api_issue_get', ['issueKey' => $issue->getKey()->toString()])]);
    }

    #[Route('/issues/{issueKey}', name: 'update', methods: ['PATCH'])]
    public function update(
        #[ValueResolver(IssueKeyResolver::class)] Issue $issue,
        #[MapRequestPayload(acceptFormat: 'json')] UpdateIssueInput $input,
    ): JsonResponse {
        $actor = $this->currentActor->get();
        $updated = $this->commands->update($issue->getKey(), $input->title, $input->problem, $input->plan, $input->result, $input->followUps, $actor, $input->session, $input->approval, $input->category, $input->requester, $input->assignee);

        return $this->json($this->issues->view($updated, $actor));
    }

    #[Route('/issues/{issueKey}/status', name: 'set_status', methods: ['POST'])]
    public function setStatus(
        #[ValueResolver(IssueKeyResolver::class)] Issue $issue,
        #[MapRequestPayload(acceptFormat: 'json')] IssueStatusInput $input,
    ): JsonResponse {
        \assert(null !== $input->status);
        $actor = $this->currentActor->get();
        $moved = $this->commands->setStatus($issue->getKey(), $input->status, $input->note, $input->reviewer, $actor, $input->session, $input->waitingFor);

        return $this->json($this->issues->view($moved, $actor));
    }

    /**
     * D132: reads each clone as it is and stores nothing. D138: requests an asynchronous fetch of a local repository in
     * `idle`, at most once in 10 s per repository.
     */
    #[Route('/issues/{issueKey}/commits', name: 'commits', methods: ['GET'])]
    public function commits(
        #[ValueResolver(IssueKeyResolver::class)] Issue $issue,
        EntityManagerInterface $entityManager,
        FetchRepository $fetchRepository,
        LoggerInterface $logger,
        #[Target('issue_commits_fetch')] RateLimiterFactoryInterface $fetchLimiter,
    ): JsonResponse {
        /** @var list<Repository> $repositories */
        $repositories = $entityManager->createQueryBuilder()
            ->select('repository')
            ->from(Repository::class, 'repository')
            ->where('repository.project = :project')
            ->setParameter('project', $issue->getProject()->getId(), ProjectId::class)
            ->orderBy('LOWER(repository.name)')
            ->addOrderBy('repository.id')
            ->getQuery()
            ->getResult();
        $items = [];
        $unavailable = [];

        foreach ($repositories as $repository) {
            $name = $repository->getName()->toString();

            if (RepositorySource::Local === $repository->getSource() && SyncState::Idle === $repository->getSyncState() && $fetchLimiter->create($repository->getId()->toString())->consume()->isAccepted()) {
                try {
                    $fetchRepository->request($repository->getId());
                } catch (NotFound|RepositoryUnavailable) {
                    // The read goes on as without the request.
                }
            }

            try {
                $reader = CodeAccess::Clone === $repository->getCodeAccess() ? $fetchRepository->readyReader($repository->getId()) : null;
            } catch (RepositoryUnavailable $error) {
                $unavailable[] = ['repository' => $name, 'reason' => $error->details()['reason']->value];

                continue;
            }

            if (!$reader instanceof GitCodeReader) {
                $unavailable[] = ['repository' => $name, 'reason' => 'no_server_copy'];

                continue;
            }

            try {
                array_push($items, ...self::commitsOf($repository, $reader, $issue->getKey()));
            } catch (GitFailed $failure) {
                $logger->error('I12 could not read the clone of the repository {repositoryId}.', ['repositoryId' => $repository->getId()->toString(), 'exception' => $failure]);
                $unavailable[] = ['repository' => $name, 'reason' => 'read_failed'];
            }
        }

        usort($items, static fn (array $first, array $second): int => $second['committedAt'] <=> $first['committedAt']);

        return $this->json(new IssueCommits($items, $unavailable));
    }

    #[Route('/issues/{issueKey}/rank', name: 'rank', methods: ['POST'])]
    #[IsGranted(User::ROLE, message: CurrentActor::SESSION_ONLY)]
    public function rank(
        #[ValueResolver(IssueKeyResolver::class)] Issue $issue,
        #[MapRequestPayload(acceptFormat: 'json')] RankIssueInput $input,
    ): JsonResponse {
        if ((null === $input->before) === (null === $input->after)) {
            throw InvalidInput::field('before', 'Give exactly one of before and after.');
        }

        $target = null === $input->before ? IssueKey::fromString((string) $input->after, 'after') : IssueKey::fromString($input->before, 'before');

        return $this->json($this->issues->view($this->commands->rank($issue->getKey(), $target, null === $input->before), $this->currentActor->get()));
    }

    #[Route('/issues/{issueKey}/release', name: 'release', methods: ['POST'])]
    #[IsGranted(User::ROLE, message: CurrentActor::SESSION_ONLY)]
    public function release(#[ValueResolver(IssueKeyResolver::class)] Issue $issue): JsonResponse
    {
        return $this->json($this->issues->view($this->commands->release($issue->getKey()), $this->currentActor->get()));
    }

    /**
     * @return list<array{repository: string, sha: CommitSha, subject: string, authorName: string, committedAt: \DateTimeImmutable, onDefaultBranch: bool, branch: string|null, htmlUrl: string|null}> Newest first
     */
    private static function commitsOf(Repository $repository, GitCodeReader $reader, IssueKey $key): array
    {
        // An issue key holds no metacharacter of either syntax.
        $inMessage = "(^|[^A-Za-z0-9_]){$key}([^A-Za-z0-9_]|$)";
        $branches = $reader->branches();
        $default = $repository->getDefaultBranch();
        $default = in_array($default, $branches, true) ? $default : null;
        $others = array_values(array_filter($branches, static fn (string $branch): bool => $branch !== $default));
        $named = array_values(array_filter($others, static fn (string $branch): bool => 1 === preg_match("/(?<![A-Za-z0-9_]){$key}(?![A-Za-z0-9_])/i", $branch)));
        /** @var array<string, array{LogCommit, string|null}> $found The commit and its branch, null on the default branch, by SHA */
        $found = [];
        /**
         * The first log with a commit gives its branch, so a branch with the key in its name wins over another branch.
         *
         * @param list<array{LogCommit, string}> $log
         */
        $add = static function (array $log, bool $onDefaultBranch) use (&$found): void {
            foreach ($log as [$commit, $branch]) {
                $found[$commit->sha->toString()] ??= [$commit, $onDefaultBranch ? null : $branch];
            }
        };

        if (null !== $default) {
            $add($reader->branchLog([$default], null, $inMessage, self::COMMITS_PER_REPOSITORY), true);
        }
        // The other branches with a commit with the key in its message, which branchOnlyLog() then reads one by one.
        $withKey = [] === $others ? [] : $reader->branchLog($others, $default, $inMessage, self::COMMITS_PER_REPOSITORY);
        foreach ($named as $branch) {
            $add($reader->branchOnlyLog($branch, $default, null, self::COMMITS_PER_REPOSITORY), false);
        }
        foreach (array_diff(array_unique(array_column($withKey, 1)), $named) as $branch) {
            $add($reader->branchOnlyLog($branch, $default, $inMessage, self::COMMITS_PER_REPOSITORY), false);
        }

        usort($found, static fn (array $first, array $second): int => $second[0]->committedAt <=> $first[0]->committedAt);
        $commitUrl = RepositorySource::Github === $repository->getSource() ? "https://github.com/{$repository->getName()}/commit/" : null;

        return array_map(static fn (array $entry): array => [
            'repository' => $repository->getName()->toString(),
            'sha' => $entry[0]->sha,
            'subject' => $entry[0]->subject,
            'authorName' => $entry[0]->authorName,
            'committedAt' => $entry[0]->committedAt,
            'onDefaultBranch' => null === $entry[1],
            'branch' => $entry[1],
            'htmlUrl' => null === $commitUrl ? null : $commitUrl.$entry[0]->sha,
        ], array_slice($found, 0, self::COMMITS_PER_REPOSITORY));
    }
}
