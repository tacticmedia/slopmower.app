<?php

declare(strict_types=1);

namespace App\Tests\Functional\Messenger;

use App\Domain\Identity\TenantId;
use App\Entity\Tenant;
use App\Messenger\TenantStamp;
use App\Tenancy\TenantContext;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\Messenger\TenantlessProbe;
use App\Tests\Support\Messenger\TenantProbe;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * The ReceivedStamp makes the bus handle an envelope as the worker and the sync:// transport do.
 */
final class MessengerTenantContextTest extends KernelTestCase
{
    use ResetDatabase;

    #[TestDox('A stamped message runs under its tenant')]
    public function testStampedMessageRunsUnderItsTenant(): void
    {
        self::newTenant('ann');
        $tenantId = self::newTenant('bob');

        $result = self::receive(new TenantProbe(), new TenantStamp($tenantId));

        self::assertSame(['context' => $tenantId->toString(), 'visibleTenants' => [$tenantId->toString()]], $result);
    }

    #[TestDox('In the worker, the tenant context and the entity manager are cleared after each message')]
    public function testWorkerClearsContextAndEntityManager(): void
    {
        $tenantId = self::newTenant('ann');

        $result = self::receive(new TenantProbe(), new TenantStamp($tenantId));

        self::assertSame([$tenantId->toString()], $result['visibleTenants']);
        self::assertNull(self::tenantContext()->current());
        self::assertSame(0, self::entityManager()->getUnitOfWork()->size());
    }

    #[TestDox('A message handled inside a request restores the tenant context of that request')]
    public function testMessageInsideARequestRestoresTheContext(): void
    {
        $requestTenantId = self::newTenant('ann');
        $messageTenantId = self::newTenant('bob');
        self::tenantContext()->set($requestTenantId);
        $requestTenant = self::entityManager()->find(Tenant::class, $requestTenantId) ?? self::fail('No tenant.');

        $result = self::receive(new TenantProbe(), new TenantStamp($messageTenantId));

        self::assertSame($messageTenantId->toString(), $result['context']);
        self::assertEquals($requestTenantId, self::tenantContext()->current());
        self::assertTrue(self::entityManager()->contains($requestTenant));
    }

    #[TestDox('A message without a stamp fails closed')]
    public function testMessageWithoutStampFailsClosed(): void
    {
        $this->expectException(UnrecoverableMessageHandlingException::class);

        self::receive(new TenantProbe());
    }

    #[TestDox('A message class on the tenantless list runs without a tenant context')]
    public function testTenantlessMessageRunsWithoutContext(): void
    {
        self::assertNull(self::receive(new TenantlessProbe()));
    }

    private static function receive(object $message, StampInterface ...$stamps): mixed
    {
        $envelope = self::getContainer()->get(MessageBusInterface::class)->dispatch(new Envelope($message, [...$stamps, new ReceivedStamp('async')]));

        return ($envelope->last(HandledStamp::class) ?? self::fail('No handler ran.'))->getResult();
    }

    private static function newTenant(string $login): TenantId
    {
        return TenantFixtures::of(self::getContainer())->withLogin($login)->create()->getTenantId();
    }

    private static function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    private static function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
