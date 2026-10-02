<?php

declare(strict_types=1);

namespace App\Docs;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Rewrites a link to `<slug>.md` of a known page to /docs/<slug>, so docs/user reads the same on GitHub and in the app.
 */
#[AutoconfigureTag('twig.markdown.league_extension')]
final readonly class DocsLinkRewriter implements ExtensionInterface
{
    private const string PAGE_LINK = '/^(?<slug>[a-z0-9-]+)\.md(?<fragment>#.*)?$/';

    public function __construct(
        private DocsCatalog $catalog,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addEventListener(DocumentParsedEvent::class, $this->rewrite(...));
    }

    private function rewrite(DocumentParsedEvent $event): void
    {
        $slugs = $this->catalog->pageSlugs();
        foreach ($event->getDocument()->iterator() as $node) {
            if (!$node instanceof Link || 1 !== preg_match(self::PAGE_LINK, $node->getUrl(), $match) || !\in_array($match['slug'], $slugs, true)) {
                continue;
            }
            $node->setUrl($this->urlGenerator->generate('docs_page', ['slug' => $match['slug']]).($match['fragment'] ?? ''));
        }
    }
}
