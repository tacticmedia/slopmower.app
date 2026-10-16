<?php

declare(strict_types=1);

namespace App\Tests\Support\GitHub;

use App\Application\RequestCommands;
use App\Domain\Actor\Actor;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RefName;
use App\Domain\Identity\ReviewId;
use App\Domain\Repository\RepositoryName;
use App\Domain\Request\Command\Claim;
use App\Domain\Request\Command\Open;
use App\Entity\PullRequest;
use App\Entity\Repository;
use App\Entity\Review;
use App\Entity\User;
use App\Message\ReadPullRequestJob;
use App\Tenancy\TenantContext;
use App\Tests\Support\Fixtures\TenantFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Messenger\Envelope;

/**
 * One request of acme/billing, the GitHub repository 812345 of installation 5551, links pull requests 42 without a
 * record, 43 with a read state, and 44 with a record without one. For tests that use GitHubRows and QueuedMessages.
 */
trait LinkedPullRequests
{
    private const array CONTENTS = ['contents' => 'read', 'metadata' => 'read'];

    private const array PULL_REQUESTS = [...self::CONTENTS, 'pull_requests' => 'read'];

    private const array CHECKS = [...self::PULL_REQUESTS, 'checks' => 'write'];

    /**
     * Empties the queue.
     *
     * @param array<string, string> $permissions The accepted permissions of installation 5551
     *
     * @return array{User, list<string>} The GitHub user 1 and the IDs of the records
     */
    private static function linkPullRequests(array $permissions, ?\DateTimeImmutable $suspendedAt = null): array
    {
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $commands = $container->get(RequestCommands::class);
        $now = Clock::get()->now();
        $user = TenantFixtures::of($container)->withGitHubUser(1)->create();
        $agent = Actor::agent($user->getId(), 'claude-code laptop');
        self::storeInstallation($user->getTenantId(), suspendedAt: $suspendedAt, permissions: $permissions);
        $container->get(TenantContext::class)->set($user->getTenantId());
        $repository = Repository::gitHub($user->getTenantId(), RepositoryName::github('acme/billing'), 812345, 5551, true, $now);
        $review = new Review(ReviewId::generate(), $repository, 'main @ 79f8595a4c1e', RefName::fromString('main'), CommitSha::fromString('79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091'), Actor::user($user->getId()), $now, 1);
        $entityManager->persist($repository);
        $entityManager->persist($review);
        $entityManager->flush();
        [$opened] = $commands->open($review->getId(), new Open(null, 'Use `Money` instead of `float` for totals.'), Actor::user($user->getId()));
        $requestId = $opened->requestId ?? self::fail('request.opened has no requestId.');
        $commands->claim(new Claim($requestId), $agent);

        foreach ([42, 43, 44] as $number) {
            $commands->linkPullRequest("https://github.com/acme/billing/pull/{$number}", [$requestId], null, $agent);
        }

        $read = new PullRequest($repository, 43);
        $read->record('Use Money for invoice totals', 'open', false, false, CommitSha::fromString('a1b2c3d4e5f60718293a4b5c6d7e8f9012345678'), 'acme/billing', $now);
        $unread = new PullRequest($repository, 44);
        $entityManager->persist($read);
        $entityManager->persist($unread);
        $entityManager->flush();
        $entityManager->clear();
        self::asyncTransport()->reset();

        return [$user, [$read->getId()->toString(), $unread->getId()->toString()]];
    }

    /**
     * @return list<int> The numbers, sorted
     */
    private static function queuedReads(): array
    {
        $numbers = array_map(static fn (ReadPullRequestJob $job): int => $job->number, self::queued(ReadPullRequestJob::class));
        sort($numbers);

        return $numbers;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $messageClass
     *
     * @return list<T>
     */
    private static function queued(string $messageClass): array
    {
        $messages = array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), self::asyncTransport()->getSent());

        return array_values(array_filter($messages, static fn (object $message): bool => $message instanceof $messageClass));
    }
}
