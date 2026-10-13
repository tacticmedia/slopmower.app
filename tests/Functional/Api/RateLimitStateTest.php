<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Application\RateLimits;
use App\Domain\Error\RateLimited;
use App\Domain\Identity\TenantId;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * docs/specs/README.md section "Rate limits": every php and worker process shares the limiter state.
 */
final class RateLimitStateTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use EnvironmentVariables;
    use ResetDatabase;

    #[TestDox('Two kernels with their own database session and directories see one count')]
    public function testTwoKernelsSeeOneCount(): void
    {
        $this->setEnvironmentVariable('APP_RATE_LIMIT_REVIEW_START_PER_HOUR', '2');
        self::mockTime('2026-10-13 09:00:00');
        $tenantId = TenantId::generate();
        self::getContainer()->get(RateLimits::class)->consumeReviewStart($tenantId);
        // As a container on another host, which shares no file of var/.
        $otherHost = self::getContainer()->getParameter('kernel.project_dir').'/var/test-other-host';
        $this->setEnvironmentVariable('APP_CACHE_DIR', "{$otherHost}/cache");
        $this->setEnvironmentVariable('APP_SHARE_DIR', "{$otherHost}/share");
        $otherKernel = static::createKernel();
        $otherKernel->boot();

        try {
            $otherLimits = $otherKernel->getContainer()->get('test.service_container')->get(RateLimits::class);
            $otherLimits->consumeReviewStart($tenantId);
            $otherLimits->consumeReviewStart($tenantId);
            self::fail('The third review start passed the limit of 2.');
        } catch (RateLimited $refused) {
            self::assertSame(2, $refused->limit);
        } finally {
            $otherKernel->shutdown();
        }
    }
}
