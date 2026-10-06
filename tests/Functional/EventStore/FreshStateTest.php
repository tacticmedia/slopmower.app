<?php

declare(strict_types=1);

namespace App\Tests\Functional\EventStore;

use App\Domain\Error\InvalidTransition;
use App\Domain\Request\Command\Claim;
use App\Domain\Request\RequestStatus;
use App\Repository\ChangeRequestRepository;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Domain\RequestScenario;
use App\Tests\Support\EventStore\CommandFixtures;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FreshStateTest extends KernelTestCase
{
    use CommandFixtures;
    use ResetDatabase;

    #[TestDox('A command decides on the stored state, not on a stale entity in the identity map')]
    public function testCommandDecidesOnTheStoredState(): void
    {
        $requestId = self::openRequest(self::startReview(self::newRepository(self::enterNewTenant())));
        $stale = self::getContainer()->get(ChangeRequestRepository::class)->find($requestId) ?? self::fail('The request is missing.');

        // Another process verifies the request after this one loaded it.
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE change_request SET status = 'verified', resolution = 'fixed' WHERE id = ?", [$requestId->toRfc4122()]);
        self::assertSame(RequestStatus::Open, $stale->getStatus());

        $this->expectException(InvalidTransition::class);
        self::requestCommands()->claim(new Claim($requestId), RequestScenario::claimingAgent());
    }
}
