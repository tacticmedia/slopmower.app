<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Application\FetchRepository;
use App\Entity\ChangeRequest;
use App\Entity\Repository;
use App\Repository\ChangeRequestRepository;
use App\Tenancy\TenantContext;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\E2E\RequestScreens;
use App\Tests\Support\Git\FixtureRepositories;
use Doctrine\ORM\EntityManagerInterface;
use Facebook\WebDriver\WebDriverBy;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Panther\PantherTestCase;

/**
 * Line 11 of the small file is in the `total()` method on lines 9 to 12.
 */
final class OutdatedLabelTest extends PantherTestCase
{
    use RequestScreens;
    use ResetDatabase;

    private const string OUTDATED = '[data-testid="outdated-label"]';
    private const string REQUEST_ROWS = '[data-testid="request-list"] tbody tr';

    private string $directory;

    private Repository $repository;

    #[TestDox('After a default-branch commit edits the region and the user clicks Refresh, the request shows Outdated')]
    public function testRefreshAfterAnEditShowsOutdated(): void
    {
        $this->reviewOfCodeView();
        $requestId = $this->seededRequest(9, 12, 'Extract the tax calculation.')->toString();
        $this->editLine11();
        $client = self::browserSignedInAs($this->commenter);
        $client->request('GET', "/repositories/{$this->repository->getId()}");
        $client->waitFor('//button[normalize-space()="Refresh"]');

        $client->getWebDriver()->findElement(WebDriverBy::xpath('//button[normalize-space()="Refresh"]'))->click();

        $client->wait()->until(fn (): bool => [true] === $this->outdatedFlags(), 'The refresh did not mark the request outdated.');
        self::openRequest($client, $requestId);
        self::assertSelectorTextSame(self::DETAIL.' header '.self::OUTDATED, 'Outdated');
        self::assertSame('changed', $client->getCrawler()->filter('[data-testid="location-info"]')->attr('data-status'));
        $this->openFile($client, FixtureRepositories::SMALL_FILE);
        $client->waitForElementToContain(self::SAVED_THREAD.' '.self::OUTDATED, 'Outdated');
    }

    #[TestDox('The outdated filter lists the request')]
    public function testOutdatedFilterListsTheRequest(): void
    {
        $this->reviewOfCodeView();
        $this->seededRequest(9, 12, 'Extract the tax calculation.');
        $this->seededRequest(3, 3, 'Is strict_types needed here?');
        $this->editLine11();
        self::getContainer()->get(TenantContext::class)->set($this->commenter->getTenantId());
        self::getContainer()->get(FetchRepository::class)->fetchNow($this->repository->getId());
        self::runQueuedMessages();
        self::assertSame([true, false], $this->outdatedFlags());
        $client = self::browserSignedInAs($this->commenter);
        $client->request('GET', "/reviews/{$this->reviewId}/requests");
        $client->waitFor(self::REQUEST_ROWS);
        self::assertCount(2, $client->getCrawler()->filter(self::REQUEST_ROWS));

        $client->getWebDriver()->findElement(WebDriverBy::xpath('//*[@data-testid="request-filters"]//label[normalize-space()="Outdated"]'))->click();

        $client->wait()->until(static fn (): bool => 1 === \count($client->getWebDriver()->findElements(WebDriverBy::cssSelector(self::REQUEST_ROWS))));
        self::assertStringContainsString('outdated=true', $client->getCurrentURL());
        self::assertSelectorTextContains(self::REQUEST_ROWS, 'Extract the tax calculation.');
        self::assertSelectorTextSame(self::REQUEST_ROWS.' '.self::OUTDATED, 'Outdated');
    }

    private function reviewOfCodeView(): void
    {
        $this->commenter = $this->user();
        $this->directory = $this->localSource(FixtureRepositories::codeView());
        $this->repository = self::connectedRepository($this->commenter, $this->directory);
        $this->reviewId = self::startedReview($this->commenter, $this->repository, 'Outdated');
    }

    private function editLine11(): void
    {
        $lines = explode("\n", FixtureRepositories::codeView()[FixtureRepositories::SMALL_FILE]);
        $lines[10] = '        return $net + intdiv($net * $taxBasisPoints, 100);';
        self::commitInLocalSource($this->directory, [FixtureRepositories::SMALL_FILE => implode("\n", $lines)], 'Use percent');
    }

    /**
     * @return list<bool|null> The flags in request ID order
     */
    private function outdatedFlags(): array
    {
        $container = self::getContainer();
        $container->get(TenantContext::class)->set($this->commenter->getTenantId());
        $container->get(EntityManagerInterface::class)->clear();
        $requests = $container->get(ChangeRequestRepository::class)->findBy([], ['id' => 'ASC']);

        return array_map(static fn (ChangeRequest $request): ?bool => $request->getOutdated(), $requests);
    }
}
