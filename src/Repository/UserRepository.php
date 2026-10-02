<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Identity\UserId;
use App\Domain\User\SignInMethod;
use App\Entity\Tenant;
use App\Entity\User;
use App\Security\TenantBeingDeleted;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

/**
 * The sign-in lookups run inside {@see \App\Tenancy\TenantFilter::suspend()}. A user without a tenant row counts as gone.
 *
 * @extends ServiceEntityRepository<User>
 */
final class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * @throws TenantBeingDeleted
     */
    public function findSignedIn(UserId $id): ?User
    {
        return $this->signInUser($this->signInQuery()
            ->where('appUser.id = :id')
            ->setParameter('id', Ulid::fromString($id->toString()), UlidType::NAME));
    }

    /**
     * @throws TenantBeingDeleted
     */
    public function findLocalByLogin(string $login): ?User
    {
        return $this->signInUser($this->signInQuery()
            ->where('appUser.signInMethod = :local')
            ->andWhere('appUser.login = :login')
            ->setParameter('local', SignInMethod::Local)
            ->setParameter('login', $login));
    }

    /**
     * @throws TenantBeingDeleted
     */
    public function findByGitHubUserId(int $githubUserId): ?User
    {
        return $this->signInUser($this->signInQuery()
            ->where('appUser.githubUserId = :githubUserId')
            ->setParameter('githubUserId', $githubUserId));
    }

    private function signInQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('appUser')
            ->addSelect('tenant.deletionRequestedAt')
            ->join(Tenant::class, 'tenant', Join::WITH, 'tenant.id = appUser.tenantId');
    }

    /**
     * The one place that refuses a tenant that is being deleted.
     *
     * @throws TenantBeingDeleted
     */
    private function signInUser(QueryBuilder $query): ?User
    {
        /** @var array{0: User, deletionRequestedAt: ?\DateTimeImmutable}|null $row */
        $row = $query->getQuery()->getOneOrNullResult();

        if (null !== $row && null !== $row['deletionRequestedAt']) {
            throw new TenantBeingDeleted();
        }

        return $row[0] ?? null;
    }
}
