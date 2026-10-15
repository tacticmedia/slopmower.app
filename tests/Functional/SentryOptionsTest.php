<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Http\SentryClientErrorFilter;
use App\Tests\Support\Environment\ProdContainer;
use PHPUnit\Framework\Attributes\TestDox;
use Sentry\DataCollection\DataCollectionPolicy;
use Sentry\DataCollection\HttpHeaderCollector;
use Sentry\DataCollection\HttpMessageType;
use Sentry\DataCollection\HttpUrlCollector;
use Sentry\Options;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverMiddleware;
use Sentry\SentryBundle\Tracing\HttpClient\TraceableHttpClient;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class SentryOptionsTest extends KernelTestCase
{
    private static ?ContainerBuilder $container = null;

    #[TestDox('In prod, an error event gets no user, cookie, body, frame argument, query data, message content, or AI input or output')]
    public function testCollectsNoUserDataBodiesOrFrameArguments(): void
    {
        $dataCollection = self::options()->getDataCollection() ?? self::fail('The prod options keep the legacy data collection.');

        self::assertFalse($dataCollection->shouldCollectUserInfo());
        self::assertTrue($dataCollection->getCookies()->isOff());
        self::assertSame([], $dataCollection->getHttpBodies());
        self::assertTrue($dataCollection->getStackFrameVariables()->isOff());
        self::assertFalse($dataCollection->shouldCollectDatabaseQueryData());
        self::assertFalse($dataCollection->shouldCollectQueues());
        self::assertSame(['inputs' => false, 'outputs' => false], $dataCollection->getGenAi());
    }

    #[TestDox('In prod, an error event filters the query parameters with user or agent text, and the headers with an IP address or a credential')]
    public function testFiltersTextQueryParametersAndSensitiveHeaders(): void
    {
        $policy = DataCollectionPolicy::fromOptions(self::options());

        self::assertSame(
            ['q=[Filtered]&limit=20', 'name=[Filtered]&source=github', 'confirm=[Filtered]', 'code=[Filtered]&state=s1', 'path=src%2FA.php&line=12&column=24&cursor=c1'],
            array_map(
                static fn (string $query): ?string => HttpUrlCollector::collectQueryString($policy, $query),
                ['q=taxrate&limit=20', 'name=acme%2Fbilling&source=github', 'confirm=janklan', 'code=c0de&state=s1', 'path=src%2FA.php&line=12&column=24&cursor=c1'],
            ),
        );
        self::assertSame([
            'X-Forwarded-For' => ['[Filtered]'],
            'Forwarded' => ['[Filtered]'],
            'X-Real-IP' => ['[Filtered]'],
            'True-Client-IP' => ['[Filtered]'],
            'CF-Connecting-IP' => ['[Filtered]'],
            'Authorization' => ['[Filtered]'],
            'Mcp-Session-Id' => ['[Filtered]'],
            'User-Agent' => ['claude-code/2.1'],
        ], HttpHeaderCollector::collect($policy, HttpMessageType::incomingRequest(), [
            'X-Forwarded-For' => ['203.0.113.7'],
            'Forwarded' => ['for=203.0.113.7'],
            'X-Real-IP' => ['203.0.113.7'],
            'True-Client-IP' => ['203.0.113.7'],
            'CF-Connecting-IP' => ['203.0.113.7'],
            'Authorization' => ['Bearer sm_secret'],
            'Mcp-Session-Id' => ['session-1'],
            'Cookie' => ['PHPSESSID=abc'],
            'User-Agent' => ['claude-code/2.1'],
        ]));
    }

    #[TestDox('In prod, the before_send callback is the client error filter')]
    public function testBeforeSendIsTheClientErrorFilter(): void
    {
        self::assertInstanceOf(SentryClientErrorFilter::class, self::options()->getBeforeSendCallback());
    }

    #[TestDox('In prod, no Sentry tracing decorates the HTTP clients or the database driver')]
    public function testNoTracingDecorators(): void
    {
        self::assertFalse(self::prodContainer()->hasDefinition(TraceableHttpClient::class));
        self::assertFalse(self::prodContainer()->hasDefinition(TracingDriverMiddleware::class));
    }

    private static function options(): Options
    {
        $options = self::prodContainer()->get('sentry.client.options'); // @phpstan-ignore symfonyContainer.serviceNotFound (a prod service)
        self::assertInstanceOf(Options::class, $options);

        return $options;
    }

    private static function prodContainer(): ContainerBuilder
    {
        return self::$container ??= ProdContainer::build('sentry.client.options');
    }
}
