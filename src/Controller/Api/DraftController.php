<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\AnchorFactory;
use App\Application\Query\RequestQuery;
use App\Application\RequestCommands;
use App\Controller\Api\Input\AnchorInput;
use App\Controller\Api\Input\CreateDraftInput;
use App\Controller\Api\Input\SubmitDraftsInput;
use App\Controller\Api\Input\UpdateDraftInput;
use App\Domain\Anchor\Anchor;
use App\Domain\Draft\DraftKind;
use App\Domain\Error\InvalidInput;
use App\Domain\Error\NotFound;
use App\Domain\Identity\RequestId;
use App\Entity\ChangeRequest;
use App\Entity\Draft;
use App\Entity\Review;
use App\Entity\User;
use App\Repository\ChangeRequestRepository;
use App\Repository\DraftRepository;
use App\Security\CurrentActor;
use App\Security\Voter\DraftVoter;
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
use Symfony\Component\Uid\Ulid;

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
            DraftKind::NewRequest => Draft::newRequest($user, $review, self::capture($anchors, $review, $input->anchor), $input->body, $now),
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
        $draft->getReview()->assertOpen();
        $draft->edit($input->body, $this->clock->now());
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

    #[Route('/api/v1/reviews/{reviewId}/drafts/submit', name: 'submit', methods: ['POST'])]
    public function submit(
        #[MapEntity(id: 'reviewId')] Review $review,
        RequestCommands $commands,
        RequestQuery $requests,
        #[MapRequestPayload(acceptFormat: 'json')] SubmitDraftsInput $input = new SubmitDraftsInput(),
    ): JsonResponse {
        return $this->json($requests->batchSubmission($commands->submitDrafts($review->getId(), $input->summary, $this->currentActor->get())), Response::HTTP_CREATED);
    }

    /**
     * As in Q2: the snapshot comes from git at the review commit.
     */
    private static function capture(AnchorFactory $anchors, Review $review, ?AnchorInput $input): Anchor
    {
        \assert(null !== $input && null !== $input->kind && null !== $input->path);

        return $anchors->capture($review, $input->kind, $input->path, $input->range?->startLine, $input->range?->endLine, $input->symbol?->fqn);
    }

    /**
     * @throws InvalidInput The ID is malformed, or the request belongs to another review
     * @throws NotFound
     */
    private function requestOfReview(Review $review, ChangeRequestRepository $requests, string $requestId): ChangeRequest
    {
        $request = $requests->find(Ulid::fromString(RequestId::fromString($requestId, 'requestId')->toString())) ?? throw new NotFound('The request does not exist.');

        return $request->getReview()->getId()->equals($review->getId())
            ? $request
            : throw InvalidInput::field('requestId', 'Must name a request of this review.');
    }
}
