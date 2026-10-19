<?php

declare(strict_types=1);

namespace App\Tests\Support\E2E;

use App\Entity\Tour;
use App\Security\LocalUserProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use Playwright\Testing\PlaywrightTestCaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Drives the e2e site in Playwright's Chromium. Tests seed app_e2e through services in the PHPUnit process
 * ({@see E2eDatabaseExtension}). Each test gets a new browser context. The browser lives for the whole PHPUnit run.
 */
abstract class FullStackTestCase extends KernelTestCase
{
    use PlaywrightTestCaseTrait;

    public const string BASE_URI = 'http://localhost:8081';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlaywright();
    }

    protected function tearDown(): void
    {
        $this->tearDownPlaywright();
        parent::tearDown();
    }

    protected function visit(string $path): void
    {
        $this->page->goto(self::BASE_URI.$path);
    }

    /**
     * Completes every part of the review tour first, so no test meets the tour.
     */
    protected function signInAs(string $login): void
    {
        $container = self::getContainer();
        $container->get(LocalUserProvisioner::class)->provision($login)->setCompletedTours(Tour::cases());
        $container->get(EntityManagerInterface::class)->flush();
        $this->visit('/_e2e/sign-in/'.$login);
    }
}
