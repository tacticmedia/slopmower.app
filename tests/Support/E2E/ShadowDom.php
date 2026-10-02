<?php

declare(strict_types=1);

namespace App\Tests\Support\E2E;

use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\Exception\StaleElementReferenceException;
use Facebook\WebDriver\Remote\RemoteWebElement;
use Facebook\WebDriver\Remote\ShadowRoot;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverElement;
use Symfony\Component\Panther\Client;

/**
 * Reads the open shadow root that `@pierre/diffs` renders code into. A shadow root takes CSS selectors only.
 *
 * The library replaces rows when the worker's highlighting arrives, so an element handle can go stale at any time.
 * Read elements only inside the check of {@see waitUntil()}.
 */
final class ShadowDom
{
    public const string CODE_HOST = 'diffs-container';

    /**
     * Queries the selector again on each attempt and passes the element to the check, until the check returns a value
     * other than null. A missing or stale element counts as not ready.
     *
     * @template T
     *
     * @param callable(WebDriverElement): (T|null) $check
     *
     * @return T
     */
    public static function waitUntil(Client $client, string $selector, callable $check): mixed
    {
        return self::attempt($client, static fn (ShadowRoot $root): mixed => $check($root->findElement(WebDriverBy::cssSelector($selector))), "No {$selector} in the code view passed the check.");
    }

    public static function count(Client $client, string $selector): int
    {
        return self::attempt($client, static fn (ShadowRoot $root): int => \count($root->findElements(WebDriverBy::cssSelector($selector))), 'The code view has no shadow root.');
    }

    /**
     * @template T
     *
     * @param callable(ShadowRoot): (T|null) $read
     *
     * @return T
     */
    private static function attempt(Client $client, callable $read, string $timeoutMessage): mixed
    {
        $result = null;
        $client->wait()->until(static function () use ($client, $read, &$result): bool {
            try {
                $result = $read(self::root($client));

                return null !== $result;
            } catch (NoSuchElementException|StaleElementReferenceException) {
                return false;
            }
        }, $timeoutMessage);
        \assert(null !== $result);

        return $result;
    }

    private static function root(Client $client): ShadowRoot
    {
        $host = $client->getWebDriver()->findElement(WebDriverBy::cssSelector(self::CODE_HOST));
        \assert($host instanceof RemoteWebElement);

        return $host->getShadowRoot();
    }
}
