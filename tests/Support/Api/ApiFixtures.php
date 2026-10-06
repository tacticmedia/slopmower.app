<?php

declare(strict_types=1);

namespace App\Tests\Support\Api;

use App\Domain\Actor\Actor;
use App\Domain\Anchor\Anchor;
use App\Domain\Anchor\AnchorKind;
use App\Domain\ApiToken\ApiTokenSecret;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RefName;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Domain\Repository\RepositoryName;
use App\Entity\ApiToken;
use App\Entity\ChangeRequest;
use App\Entity\Draft;
use App\Entity\Repository;
use App\Entity\Review;
use App\Entity\User;
use App\Tenancy\TenantFilter;
use App\Tests\Support\Fixtures\TenantFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\Clock;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signed-in clients and rows for WebTestCase tests that use ResetDatabase.
 */
trait ApiFixtures
{
    private static function signIn(KernelBrowser $client, string $login = 'jan'): User
    {
        $user = TenantFixtures::of(self::getContainer())->withLogin($login)->create();
        $client->loginUser($user, 'web');

        return $user;
    }

    /**
     * A local repository row without a clone. Clears the entity manager, so a request reads it through the SQL filter.
     */
    private static function storeRepository(TenantId $tenantId, string $directory): Repository
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $repository = Repository::local($tenantId, RepositoryName::local($directory), $directory, Clock::get()->now());
        $entityManager->persist($repository);
        $entityManager->flush();
        $entityManager->clear();

        return $repository;
    }

    /**
     * An API token of the user, created without T2, so a test can store any expiry. Clears the entity manager.
     *
     * @return array{ApiToken, string} The token and its secret
     */
    private static function storeToken(
        User $user,
        ?Repository $repository = null,
        ?\DateTimeImmutable $expiresAt = null,
        bool $revoked = false,
        string $name = 'claude-code laptop',
    ): array {
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        [$owner, $scope] = $container->get(TenantFilter::class)->suspend(static fn (): array => [
            $entityManager->find(User::class, $user->getId()),
            null === $repository ? null : $entityManager->find(Repository::class, $repository->getId()),
        ]);
        \assert($owner instanceof User);
        $now = Clock::get()->now();
        $secret = ApiTokenSecret::generate();
        $token = new ApiToken($owner, $name, $scope, $secret, $now, $expiresAt);

        if ($revoked) {
            $token->revoke($now);
        }

        $entityManager->persist($token);
        $entityManager->flush();
        $entityManager->clear();

        return [$token, $secret->value];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private static function bearerRequest(KernelBrowser $client, #[\SensitiveParameter] string $secret, string $method, string $uri, ?array $body = null): Response
    {
        return self::apiRequest($client, $method, $uri, $body, ['HTTP_AUTHORIZATION' => "Bearer {$secret}"]);
    }

    /**
     * An open review row of the user, without events, of a new local repository row without a clone. Clears the
     * entity manager.
     */
    private static function storeReview(User $user, string $directory): Review
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $now = Clock::get()->now();
        $repository = Repository::local($user->getTenantId(), RepositoryName::local($directory), $directory, $now);
        $review = new Review(ReviewId::generate(), $repository, 'main @ 79f8595a4c1e', RefName::fromString('main'), CommitSha::fromString('79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091'), Actor::user($user->getId()), $now, 1);
        $entityManager->persist($repository);
        $entityManager->persist($review);
        $entityManager->flush();
        $entityManager->clear();

        return $review;
    }

    /**
     * An open file request row of the review, without events. Clears the entity manager.
     */
    private static function storeRequest(Review $review): ChangeRequest
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $stored = self::getContainer()->get(TenantFilter::class)->suspend(static fn (): ?Review => $entityManager->find(Review::class, $review->getId()));
        \assert($stored instanceof Review);
        $anchor = Anchor::of(AnchorKind::File, RepoPath::fromString('README.md'), $stored->getCommitSha(), BlobSha::fromString('e965047ad7c57865823c7d992b1d046ea66edf78'), null, null, null);
        $request = new ChangeRequest(RequestId::generate(), $stored, $anchor, 'Use `Money` instead of `float` for totals.', $stored->getStartedBy(), Clock::get()->now());
        $entityManager->persist($request);
        $entityManager->flush();
        $entityManager->clear();

        return $request;
    }

    /**
     * A reply draft of the user to the request. Clears the entity manager.
     */
    private static function storeDraft(User $user, ChangeRequest $request): Draft
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $draft = self::getContainer()->get(TenantFilter::class)->suspend(static function () use ($entityManager, $user, $request): Draft {
            $owner = $entityManager->find(User::class, $user->getId());
            $stored = $entityManager->find(ChangeRequest::class, $request->getId());
            \assert($owner instanceof User && $stored instanceof ChangeRequest);
            $draft = Draft::reply($owner, $stored, 'Keep the rounding mode configurable.', Clock::get()->now());
            $entityManager->persist($draft);
            $entityManager->flush();

            return $draft;
        });
        $entityManager->clear();

        return $draft;
    }

    /**
     * A JSON request that passes the same-origin check unless $server overrides Sec-Fetch-Site.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $server
     */
    private static function apiRequest(KernelBrowser $client, string $method, string $uri, ?array $body = null, array $server = []): Response
    {
        $client->request(
            $method,
            $uri,
            server: $server + ['CONTENT_TYPE' => 'application/json', 'HTTP_SEC_FETCH_SITE' => 'same-origin'],
            content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR),
        );

        return $client->getResponse();
    }
}
