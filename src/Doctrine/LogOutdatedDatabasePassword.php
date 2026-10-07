<?php

declare(strict_types=1);

namespace App\Doctrine;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use TacticMedia\RdsAuth\ConfiguredPasswordOutdated;

/**
 * Error, not warning: the prod fingers_crossed handler drops a warning that no error follows.
 */
#[AsEventListener]
final class LogOutdatedDatabasePassword
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ConfiguredPasswordOutdated $outdated): void
    {
        $this->logger->error('The database refused the deployed password and accepted the current one from Secrets Manager. Redeploy to load it.', get_object_vars($outdated));
    }
}
