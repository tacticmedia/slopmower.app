<?php

declare(strict_types=1);

namespace App\Tests\Support\Messenger;

use App\Application\CodeReaders;
use App\Repository\RepositoryRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class ScratchProbeHandler
{
    public function __construct(
        private RepositoryRepository $repositories,
        private CodeReaders $readers,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(ScratchProbe $probe): never
    {
        $repository = $this->repositories->findFresh($probe->repositoryId) ?? throw new \LogicException('The probe needs a repository.');
        $reader = $this->readers->of($repository);
        $reader->filesContaining($probe->commit, 'Hello');
        $reader->filesContaining($probe->commit, 'Hello');

        throw new \RuntimeException('The probe fails after the scratch copy.');
    }
}
