<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\IssueCommands;
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
use App\Controller\Api\Input\UpdateIssueInput;
use App\Domain\Identity\IssueEntryId;
use App\Domain\Identity\ProjectId;
use App\Domain\Issue\CommentLabel;
use App\Domain\Issue\IssueKey;
use App\Domain\Issue\IssueLinkKind;
use App\Domain\Issue\IssueStatus;
use App\Entity\Issue;
use App\Entity\IssueLink;
use App\Entity\Project;
use App\Entity\User;
use App\Security\CurrentActor;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * I1 to I11 of docs/specs/rest-api-issues.md. A path key that the SQL filters hide resolves to 404.
 */
#[Route('/api/v1', name: 'api_issue_', requirements: ['projectKey' => IssueKey::PROJECT_KEY_REQUIREMENT, 'issueKey' => IssueKey::REQUIREMENT])]
final class IssueController extends AbstractController
{
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
        return $this->json($this->issues->list($project->getKey(), $query->status, $query->limit, $query->cursor));
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

        return $this->json($this->issues->view($this->commands->comment($issue->getKey(), $input->body, $input->label ?? CommentLabel::Comment, $actor, $input->session, $replaces, $input->questions ?? []), $actor), Response::HTTP_CREATED);
    }

    #[Route('/projects/{projectKey}/comments', name: 'comments', methods: ['GET'])]
    public function comments(
        #[MapEntity(mapping: ['projectKey' => 'key'])] Project $project,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        IssueCommentListQuery $query = new IssueCommentListQuery(),
    ): JsonResponse {
        return $this->json($this->issues->comments($project->getKey(), $query->label, $query->limit, $query->cursor));
    }

    /**
     * The SQL filters leave out a link with an issue that the caller cannot see.
     */
    #[Route('/projects/{projectKey}/graph', name: 'graph', methods: ['GET'])]
    public function graph(#[MapEntity(mapping: ['projectKey' => 'key'])] Project $project, EntityManagerInterface $entityManager, ClockInterface $clock): JsonResponse
    {
        $ended = array_values(array_filter(IssueStatus::cases(), static fn (IssueStatus $status): bool => $status->isEnded()));
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
        /** @var list<Issue> $nodes */
        $nodes = $ofProject('issue', 'IN', [IssueStatus::AwaitingUserInput, IssueStatus::InReview])->getResult();
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
        \assert(null !== $input->title);
        $actor = $this->currentActor->get();
        $issue = $this->commands->create($project->getKey(), $input->title, $input->problem, $input->status ?? IssueStatus::Draft, $actor, $input->approval);

        return $this->json($this->issues->view($issue, $actor), Response::HTTP_CREATED, ['Location' => $this->generateUrl('api_issue_get', ['issueKey' => $issue->getKey()->toString()])]);
    }

    #[Route('/issues/{issueKey}', name: 'update', methods: ['PATCH'])]
    public function update(
        #[ValueResolver(IssueKeyResolver::class)] Issue $issue,
        #[MapRequestPayload(acceptFormat: 'json')] UpdateIssueInput $input,
    ): JsonResponse {
        $actor = $this->currentActor->get();
        $updated = $this->commands->update($issue->getKey(), $input->title, $input->problem, $input->plan, $input->result, $input->followUps, $actor, $input->session, $input->approval);

        return $this->json($this->issues->view($updated, $actor));
    }

    #[Route('/issues/{issueKey}/status', name: 'set_status', methods: ['POST'])]
    public function setStatus(
        #[ValueResolver(IssueKeyResolver::class)] Issue $issue,
        #[MapRequestPayload(acceptFormat: 'json')] IssueStatusInput $input,
    ): JsonResponse {
        \assert(null !== $input->status);
        $actor = $this->currentActor->get();
        $moved = $this->commands->setStatus($issue->getKey(), $input->status, $input->note, $input->reviewer, $actor, $input->session);

        return $this->json($this->issues->view($moved, $actor));
    }

    #[Route('/issues/{issueKey}/release', name: 'release', methods: ['POST'])]
    #[IsGranted(User::ROLE, message: CurrentActor::SESSION_ONLY)]
    public function release(#[ValueResolver(IssueKeyResolver::class)] Issue $issue): JsonResponse
    {
        return $this->json($this->issues->view($this->commands->release($issue->getKey()), $this->currentActor->get()));
    }
}
