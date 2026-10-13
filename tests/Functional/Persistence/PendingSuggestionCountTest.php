<?php

declare(strict_types=1);

namespace App\Tests\Functional\Persistence;

use App\Domain\Git\CommitSha;
use App\Domain\Git\RefName;
use App\Domain\Request\Command\AcceptSuggestion;
use App\Entity\Review;
use App\Projection\RequestStateMapper;
use App\Repository\ChangeRequestRepository;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Domain\RequestScenario;
use App\Tests\Support\Persistence\ReviewDataFixtures;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PendingSuggestionCountTest extends KernelTestCase
{
    use ResetDatabase;
    use ReviewDataFixtures;

    #[TestDox('The count holds the pending suggestions of the review only')]
    public function testCountsPendingSuggestionsOfTheReview(): void
    {
        $tenantId = self::enterNewTenant();
        $scenario = new RequestScenario();
        $userRequest = $scenario->open();
        $firstPending = $scenario->suggest();
        $accepted = $scenario->suggest();
        $scenario->run(new AcceptSuggestion($accepted), RequestScenario::user());
        $secondPending = $scenario->suggest();
        $review = self::newReview($tenantId, $scenario->review()->id);
        $other = RequestScenario::inState('suggestion pending');
        $otherReview = new Review($other->review()->id, $review->getRepository(), 'main @ 79f8595a4c1e', RefName::fromString('main'), CommitSha::fromString(self::REVIEW_COMMIT), RequestScenario::user(), new \DateTimeImmutable(self::TIME), 1);
        self::entityManager()->persist($otherReview);
        self::entityManager()->persist(RequestStateMapper::toEntity($otherReview, $other->state()));

        foreach ([$userRequest, $firstPending, $accepted, $secondPending] as $id) {
            self::entityManager()->persist(RequestStateMapper::toEntity($review, $scenario->state($id)));
        }

        self::entityManager()->flush();

        self::assertSame(2, self::getContainer()->get(ChangeRequestRepository::class)->countPendingSuggestions($scenario->review()->id));
    }
}
