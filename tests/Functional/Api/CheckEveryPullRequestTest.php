<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Domain\Repository\RepositoryName;
use App\Entity\Repository;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Contract\SchemaValidator;
use App\Tests\Support\Database\ResetDatabase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;

/**
 * R8 of docs/specs/rest-api.md.
 */
final class CheckEveryPullRequestTest extends WebTestCase
{
    use ApiFixtures;
    use JsonApiAssertions;
    use ResetDatabase;

    #[TestDox('R8 sets the check of every pull request of a GitHub repository, and R4 shows it')]
    public function testSetsTheValueOfAGitHubRepository(): void
    {
        $client = static::createClient();
        $user = self::signIn($client);
        $repository = Repository::gitHub($user->getTenantId(), RepositoryName::github('acme/billing'), 812345, 5551, true, Clock::get()->now());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($repository);
        $entityManager->flush();
        $path = "/api/v1/repositories/{$repository->getId()}";
        $input = ['enabled' => true];
        self::assertSame([], SchemaValidator::errors(json_encode($input, \JSON_THROW_ON_ERROR), 'inputs.schema.json#/$defs/CheckEveryPullRequestInput'));

        $set = self::assertApiResponse(self::apiRequest($client, 'POST', "{$path}/check-every-pull-request", $input), 200, 'repository.schema.json');

        $read = self::assertApiResponse(self::apiRequest($client, 'GET', $path), 200, 'repository.schema.json');
        self::assertSame([true, true], [$set['github']['checkEveryPullRequest'] ?? null, $read['github']['checkEveryPullRequest'] ?? null]);
    }

    #[TestDox('R8 gives invalid_input for a local repository')]
    public function testLocalRepositoryGivesInvalidInput(): void
    {
        $client = static::createClient();
        $user = self::signIn($client);
        $repository = self::storeRepository($user->getTenantId(), 'billing');

        $response = self::apiRequest($client, 'POST', "/api/v1/repositories/{$repository->getId()}/check-every-pull-request", ['enabled' => true]);

        self::assertApiError($response, 422, 'invalid_input');
    }
}
