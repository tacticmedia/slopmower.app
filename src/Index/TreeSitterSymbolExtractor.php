<?php

declare(strict_types=1);

namespace App\Index;

use App\Domain\Anchor\SymbolKind;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * D56: the `symbols` service parses with tree-sitter. A failure of the service throws, so nothing is stored.
 */
final class TreeSitterSymbolExtractor implements SymbolExtractor
{
    /** The keys of `LANGUAGES` in symbols/src/languages.ts. */
    private const array LANGUAGES = ['javascript', 'jsx', 'typescript', 'tsx', 'python', 'go', 'rust'];

    public function __construct(
        #[Target('symbols.client')]
        private readonly HttpClientInterface $symbolsClient,
    ) {
    }

    public function languages(): array
    {
        return self::LANGUAGES;
    }

    /**
     * @throws ExceptionInterface The service is unreachable, or answers with an error
     */
    public function extract(string $language, string $source): SymbolTable
    {
        $response = $this->symbolsClient->request('POST', '/v1/symbols', [
            'query' => ['language' => $language],
            'headers' => ['Content-Type' => 'application/octet-stream'],
            'body' => $source,
        ]);
        $payload = $response->toArray();
        $symbols = [];

        foreach ($payload['symbols'] as $row) {
            $kind = SymbolKind::tryFrom($row['kind']);
            $symbol = null === $kind ? null : ExtractedSymbol::storable(
                $kind,
                $row['fqn'],
                $row['name'],
                $row['parentFqn'],
                $row['startLine'],
                $row['endLine'],
                $row['declarationStartLine'],
                $row['docStartLine'],
            );

            if (null !== $symbol) {
                $symbols[] = $symbol;
            }
        }

        return new SymbolTable($language, $payload['parseError'], $symbols);
    }
}
