<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Live\LiveTopics;
use App\Security\CurrentActor;
use App\Tenancy\TenantContext;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A3, docs/specs/rest-api.md section 3.
 */
final class MercureAuthorizeController extends AbstractController
{
    private const int LIFETIME_SECONDS = 3600;

    /**
     * The bundle derives `Secure` from the scheme of the public hub URL. The specs derive it from the request.
     */
    #[Route('/api/v1/mercure/authorize', name: 'api_mercure_authorize', methods: ['POST'])]
    #[IsGranted(User::ROLE, message: CurrentActor::SESSION_ONLY)]
    public function __invoke(Request $request, TenantContext $tenantContext, LiveTopics $topics, Authorization $authorization, HubInterface $hub, ClockInterface $clock): JsonResponse
    {
        $selectors = $topics->subscriberSelectors($tenantContext->get());
        $expiresAt = new \DateTimeImmutable('@'.($clock->now()->getTimestamp() + self::LIFETIME_SECONDS));
        $cookie = $authorization->createCookie($request, $selectors, additionalClaims: ['exp' => $expiresAt])->withSecure($request->isSecure());
        $response = $this->json(['hubUrl' => $hub->getPublicUrl(), 'topics' => $selectors, 'expiresAt' => $expiresAt]);
        $response->headers->setCookie($cookie);

        return $response;
    }
}
