<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\Api\Input\ConfirmQuery;
use App\Domain\Error\InvalidInput;
use App\Entity\User;
use App\Http\ApiCsrfListener;
use App\Message\PurgeTenantJob;
use App\Repository\ApiTokenRepository;
use App\Repository\TenantRepository;
use App\Security\CurrentActor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A2 of docs/specs/rest-api.md section 3.
 */
final class AccountController extends AbstractController
{
    /**
     * The sign-in lookups refuse a marked tenant, so no caller gets its tenant context again.
     */
    #[Route('/api/v1/account', name: 'api_account_delete', methods: ['DELETE'])]
    #[IsGranted(User::ROLE, message: CurrentActor::SESSION_ONLY)]
    public function delete(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] ConfirmQuery $query,
        CurrentActor $currentActor,
        TenantRepository $tenants,
        ApiTokenRepository $tokens,
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
        Security $security,
        CsrfTokenManagerInterface $csrfTokenManager,
        Request $request,
        MessageBusInterface $bus,
    ): Response {
        $user = $currentActor->sessionUser();

        if ($user->getLogin() !== $query->confirm) {
            throw InvalidInput::field('confirm', 'Must equal the login of the user.');
        }

        $tenantId = $user->getTenantId();
        $now = $clock->now();

        $entityManager->wrapInTransaction(static function () use ($tenants, $tokens, $tenantId, $now): void {
            foreach ($tokens->findBy(['revokedAt' => null]) as $token) {
                $token->revoke($now);
            }

            ($tenants->find($tenantId) ?? throw new \LogicException('The tenant of the user has no row.'))->requestDeletion($now);
        });

        $security->logout(false);
        $request->getSession()->invalidate();
        $csrfCookieName = $csrfTokenManager->getToken(ApiCsrfListener::TOKEN_ID)->getValue();
        // Else SameOriginCsrfListener stores its check strategy in the new session, and the response sets its cookie.
        // Without the attribute, the listener also leaves the double-submit cookie, which clearCsrfCookies() clears.
        $request->attributes->remove($csrfCookieName);
        $bus->dispatch(new PurgeTenantJob($tenantId));

        return self::clearCsrfCookies($request, new Response(status: Response::HTTP_ACCEPTED), $csrfCookieName);
    }

    /**
     * The double-submit cookies of the request, as SameOriginCsrfListener clears them.
     */
    private static function clearCsrfCookies(Request $request, Response $response, string $csrfCookieName): Response
    {
        $prefix = ($request->isSecure() ? '__Host-' : '').$csrfCookieName.'_';

        foreach ($request->cookies->all() as $name => $value) {
            if ($csrfCookieName === $value && str_starts_with((string) $name, $prefix)) {
                $response->headers->clearCookie((string) $name, '/', null, $request->isSecure(), false, 'strict');
            }
        }

        return $response;
    }
}
