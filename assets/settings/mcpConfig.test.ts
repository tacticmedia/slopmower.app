import { describe, expect, it } from 'vitest';
import mcpSpecification from '../../docs/specs/mcp.md?raw';
import meFixture from '../../docs/specs/fixtures/me.agent.json';
import { mcpConfig } from './mcpConfig';

function specifiedClientConfig(mcpUrl: string): unknown {
    const line = mcpSpecification.split('\n').find((candidate) => candidate.startsWith('{"mcpServers"'));
    if (undefined === line) {
        throw new Error('docs/specs/mcp.md has no client configuration line.');
    }

    return JSON.parse(line.replace('https://<host>/_mcp', mcpUrl));
}

describe('McpConfigTest', () => {
    it('The snippet has the server slopmower, type http, the /_mcp URL, and the Authorization header with ${SLOPMOWER_TOKEN}', () => {
        const mcpUrl = meFixture.links.mcpUrl;

        const snippet: unknown = JSON.parse(mcpConfig(mcpUrl));

        expect(snippet).toEqual({
            mcpServers: {
                slopmower: {
                    type: 'http',
                    url: 'https://slopmower.example/_mcp',
                    headers: { Authorization: 'Bearer ${SLOPMOWER_TOKEN}' },
                },
            },
        });
        expect(snippet).toEqual(specifiedClientConfig(mcpUrl));
    });
});
