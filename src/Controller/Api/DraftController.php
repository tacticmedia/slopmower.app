<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\AnchorFactory;
use App\Application\Query\BatchSubmission;
use App\Application\Query\RequestQuery;
use App\Application\UsageLimits;
use App\Controller\Api\Input\AnchorInput;
use App\Controller\Api\Input\CreateDraftInput;
use App\Controller\Api\Input\SubmitDraftsInput;
use App\Controller\Api\Input\UpdateDraftInput;
use App\Domain\Actor\Actor;
use App\Domain\Anchor\Anchor;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Event\BatchSubmittedEvent;
use App\Domain\Event\DecidedEvent;
use App\Domain\Event\RequestCommentedEvent;
use App\Domain\Event\RequestOpenedEvent;
use App\Domain\Identity\BatchId;
use App\Domain\Identity\RequestId;
use App\Domain\Request\Command\Open;
use App\Domain\Request\Command\Reply;
use App\Domain\Request\RequestDecider;
use App\Domain\Review\ReviewSnapshot;
use App\Entity\ChangeRequest;
use App\Entity\Draft;
use App\Entity\DraftKind;
use App\Entity\Review;
use App\Entity\User;
use App\EventStore\CommandExecutor;
use App\Projection\RequestStateMapper;
use App\Repository\ChangeRequestRepository;
use App\Repository\CommitLinkRepository;
use App\Repository\DraftRepository;
use App\Repository\ReviewRepository;
use App\Security\CurrentActor;
use App\Security\Voter\DraftVoter;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * D1 to D5 of docs/specs/rest-api-requests.md section 5.
 */
#[Route(name: 'api_draft_')]
#[IsGranted(User::ROLE, message: CurrentActor::SESSION_ONLY)]
final class DraftController extends AbstractController
{
    public function __construct(
        private readonly DraftRepository $drafts,
        private readonly EntityManagerInterface $entityManager,
        private readonly CurrentActor $currentActor,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/api/v1/reviews/{reviewId}/drafts', name: 'list', methods: ['GET'])]
    public function list(#[MapEntity(id: 'reviewId')] Review $review): JsonResponse
    {
        return $this->json(['reviewId' => $review->getId(), 'items' => $this->drafts->findOfUser($this->currentActor->sessionUser()->getId(), $review->getId())]);
    }

    #[Route('/api/v1/reviews/{reviewId}/drafts', name: 'create', methods: ['POST'])]
    public function create(
        #[MapEntity(id: 'reviewId')] Review $review,
        #[MapRequestPayload(acceptFormat: 'json')] CreateDraftInput $input,
        AnchorFactory $anchors,
        ChangeRequestRepository $requests,
        LockFactory $lockFactory,
    ): JsonResponse {
        \assert(null !== $input->body);
        $user = $this->currentActor->sessionUser();
        $now = $this->clock->now();
        $draft = match ($input->kind) {
            DraftKind::Reply => Draft::reply($user, $this->requestOfReview($review, $requests, (string) $input->requestId), $input->body, $now),
            DraftKind::NewRequest => Draft::newRequest($user, $review, self::capture($anchors, $review, $input->anchor), $input->title, $input->body, $input->requireApproval ?? false, $now),
            null => throw new \LogicException('The kind is validated.'),
        };

        // The count and the insert form one step, so two parallel requests cannot pass the limit.
        $lock = $lockFactory->createLock("drafts:{$review->getId()}:{$user->getId()}", ttl: null);
        $lock->acquire(true);

        try {
            if ($this->drafts->countOfUser($user->getId(), $review->getId()) >= Draft::MAX_PER_USER_AND_REVIEW) {
                throw InvalidInput::field('', 'A user has at most 500 drafts per review. Submit or delete some first.');
            }

            $review->assertOpen();
            $this->entityManager->persist($draft);
            $this->entityManager->flush();
        } catch (ForeignKeyConstraintViolationException) {
            // A delete of the review, the request, or the user committed after the read.
            throw new NotFound();
        } finally {
            $lock->release();
        }

        return $this->json($draft, Response::HTTP_CREATED, ['Location' => $this->generateUrl('api_draft_update', ['draftId' => $draft->getId()])]);
    }

    #[Route('/api/v1/drafts/{draftId}', name: 'update', methods: ['PATCH'])]
    #[IsGranted(DraftVoter::OWN, subject: 'draft', statusCode: Response::HTTP_NOT_FOUND)]
    public function update(#[MapEntity(id: 'draftId')] Draft $draft, #[MapRequestPayload(acceptFormat: 'json')] UpdateDraftInput $input): JsonResponse
    {
        \assert(null !== $input->body);
        // The edit refuses title and requireApproval on a reply, and invalid_input comes before review_closed.
        $draft->edit($input->title, $input->body, $input->requireApproval, $this->clock->now());
        $draft->getReview()->assertOpen();
        $this->entityManager->flush();

        return $this->json($draft);
    }

    /**
     * Also on a closed review, so the user can delete the drafts that are left.
     */
    #[Route('/api/v1/drafts/{draftId}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted(DraftVoter::OWN, subject: 'draft', statusCode: Response::HTTP_NOT_FOUND)]
    public function delete(#[MapEntity(id: 'draftId')] Draft $draft): Response
    {
        $this->entityManager->remove($draft);
        $this->entityManager->flush();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * In one transaction: request.opened or request.commented per draft of the user, sorted by createdAt, then
     * batch.submitted. Deletes exactly the drafts that it read, so a draft created meanwhile stays.
     */
    #[Route('/api/v1/reviews/{reviewId}/drafts/submit', name: 'submit', methods: ['POST'])]
    public function submit(
        #[MapEntity(id: 'reviewId')] Review $review,
        CommandExecutor $executor,
        ReviewRepository $reviews,
        ChangeRequestRepository $requests,
        CommitLinkRepository $links,
        RequestQuery $requestQuery,
        UsageLimits $usageLimits,
        #[MapRequestPayload(acceptFormat: 'json')] SubmitDraftsInput $input = new SubmitDraftsInput(),
    ): JsonResponse {
        $actor = $this->currentActor->get();
        $batchId = BatchId::generate();
        /** @var list<Draft> $drafts */
        $drafts = [];

        $events = $executor->execute(
            $review->getId(),
            $actor,
            function () use ($review, $reviews, $requests, $links, $usageLimits, $batchId, $input, $actor, &$drafts): array {
                $snapshot = ($reviews->findFresh($review->getId()) ?? throw new NotFound())->toSnapshot();
                $drafts = $this->drafts->findOfUser($this->currentActor->sessionUser()->getId(), $review->getId());

                if ([] === $drafts) {
                    throw InvalidInput::field('', 'There are no drafts to submit.');
                }

                $decided = array_map(static fn (Draft $draft): DecidedEvent => self::decideDraft($draft, $batchId, $snapshot, $actor, $requests, $links), $drafts);
                $opened = array_filter($decided, static fn (DecidedEvent $event): bool => $event->event instanceof RequestOpenedEvent);
                $usageLimits->checkNewRequests($review->getTenantId(), $review->getId(), \count($opened));

                return [...$decided, new DecidedEvent(self::batchSubmitted($batchId, $input->summary, $decided))];
            },
            function () use (&$drafts): void {
                foreach ($drafts as $draft) {
                    $this->entityManager->remove($draft);
                }
            },
        );
        $submitted = array_last($events);
        $batch = $submitted?->event;

        if (!$batch instanceof BatchSubmittedEvent) {
            throw new \LogicException('A draft submission ends with batch.submitted.');
        }

        $opened = [];
        $replies = [];

        foreach ($events as $event) {
            $requestId = $event->requestId;

            if ($event->event instanceof RequestOpenedEvent && null !== $requestId) {
                $opened[] = $requests->findFresh($requestId) ?? throw new NotFound();
            } elseif ($event->event instanceof RequestCommentedEvent && null !== $requestId) {
                $replies[] = ['requestId' => $requestId, 'commentId' => $event->event->commentId];
            }
        }

        return $this->json(
            new BatchSubmission($batch->batchId, $submitted->reviewId, $batch->summary, $submitted->occurredAt, $submitted->seq, $requestQuery->summaries($opened), $replies),
            Response::HTTP_CREATED,
        );
    }

    private static function decideDraft(Draft $draft, BatchId $batchId, ReviewSnapshot $review, Actor $actor, ChangeRequestRepository $requests, CommitLinkRepository $links): DecidedEvent
    {
        $replyTo = $draft->getRequest();
        $request = null === $replyTo ? null : $requests->findFresh($replyTo->getId()) ?? throw new NotFound();
        $command = null === $request
            ? new Open($draft->getAnchor(), $draft->getBody(), $batchId, requireApproval: $draft->requiresApproval(), title: $draft->getTitle())
            : new Reply($request->getId(), $draft->getBody(), batchId: $batchId);
        $states = null === $request ? [] : [RequestStateMapper::toState($request, $links->findFreshOf($request))];

        return RequestDecider::decide($command, $states, $review, $actor)[0] ?? throw new \LogicException('A draft decides one event.');
    }

    /**
     * @param list<DecidedEvent> $decided The request events of the batch
     */
    private static function batchSubmitted(BatchId $batchId, ?string $summary, array $decided): BatchSubmittedEvent
    {
        $requestIds = [];
        $commentIds = [];

        foreach ($decided as $event) {
            $requestId = $event->requestId ?? throw new \LogicException('A draft decides a request event.');
            $requestIds[$requestId->toString()] ??= $requestId;

            if ($event->event instanceof RequestCommentedEvent) {
                $commentIds[] = $event->event->commentId;
            }
        }

        return new BatchSubmittedEvent($batchId, $summary, array_values($requestIds), $commentIds);
    }

    /**
     * As in Q2: the snapshot comes from git at the review commit. A general request has no anchor.
     */
    private static function capture(AnchorFactory $anchors, Review $review, ?AnchorInput $input): ?Anchor
    {
        if (null === $input) {
            return null;
        }

        \assert(null !== $input->kind && null !== $input->path);

        return $anchors->capture($review, $input->kind, $input->path, $input->range?->startLine, $input->range?->endLine, $input->symbol?->fqn);
    }

    /**
     * @throws InvalidInput The ID is malformed, or the request belongs to another review
     * @throws NotFound
     */
    private function requestOfReview(Review $review, ChangeRequestRepository $requests, string $requestId): ChangeRequest
    {
        $request = $requests->find(RequestId::fromString($requestId, 'requestId')) ?? throw new NotFound('The request does not exist.');

        return $request->getReview()->getId()->equals($review->getId())
            ? $request
            : throw InvalidInput::field('requestId', 'Must name a request of this review.');
    }
}
