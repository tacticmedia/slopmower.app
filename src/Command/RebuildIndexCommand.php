<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Error\InvalidInput;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Index\IndexSql;
use App\Message\IndexReview;
use App\Repository\ReviewRepository;
use App\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:index:rebuild', description: 'Resets the index status of a review and queues its index job')]
final class RebuildIndexCommand
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ReviewRepository $reviews,
        private readonly IndexSql $indexSql,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The tenant of the review')] string $tenantId,
        #[Argument('The review to index again')] string $reviewId,
        #[Option('First delete the parsed blobs and symbols of the repository, so every blob is parsed again')] bool $reparse = false,
    ): int {
        try {
            $tenant = TenantId::fromString($tenantId, 'tenantId');
            $review = ReviewId::fromString($reviewId, 'reviewId');
        } catch (InvalidInput $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }

        $this->tenantContext->set($tenant);
        $row = $this->reviews->findFresh($review);

        if (null === $row) {
            $io->error("The tenant {$tenantId} has no review {$reviewId}.");

            return Command::FAILURE;
        }

        if ($reparse) {
            $this->indexSql->deleteParsedBlobs($tenant, $row->getRepository()->getId());
        }

        $row->resetIndex();
        $this->entityManager->flush();
        $this->bus->dispatch(new IndexReview($review));
        $io->success("The index job of the review {$reviewId} is queued.");

        return Command::SUCCESS;
    }
}
