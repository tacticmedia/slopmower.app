<?php

declare(strict_types=1);

use App\Doctrine\Type\IdentifierType;
use App\Doctrine\Type\StringValueType;
use App\Domain\Git\BlobSha;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RepoPath;
use App\Domain\Identity\ApiTokenId;
use App\Domain\Identity\BatchId;
use App\Domain\Identity\CommentId;
use App\Domain\Identity\DraftId;
use App\Domain\Identity\EventId;
use App\Domain\Identity\GitHubCandidateId;
use App\Domain\Identity\GitHubInstallationId;
use App\Domain\Identity\PullRequestId;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Domain\Identity\UserId;
use App\Domain\Identity\ViewedFileId;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// One DBAL type service per value class, with the class name as the type name: a type takes its value class from the
// constructor. PHP, because YAML has no loop.
return static function (ContainerConfigurator $container): void {
    $valueClasses = [
        IdentifierType::class => [
            ApiTokenId::class,
            BatchId::class,
            CommentId::class,
            DraftId::class,
            EventId::class,
            GitHubCandidateId::class,
            GitHubInstallationId::class,
            PullRequestId::class,
            RepositoryId::class,
            RequestId::class,
            ReviewId::class,
            TenantId::class,
            UserId::class,
            ViewedFileId::class,
        ],
        StringValueType::class => [BlobSha::class, CommitSha::class, RepoPath::class],
    ];

    foreach ($valueClasses as $typeClass => $classes) {
        foreach ($classes as $valueClass) {
            $container->services()
                ->set("app.doctrine_type.{$valueClass}", $typeClass)
                ->args([$valueClass])
                ->tag('doctrine.dbal.type', ['type_name' => $valueClass]);
        }
    }
};
