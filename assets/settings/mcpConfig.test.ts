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
    it('The snippet has the server holistic-review, type http, the /_mcp URL, and the Authorization header with ${HOLISTIC_REVIEW_TOKEN}', () => {
        const mcpUrl = meFixture.links.mcpUrl;

        const snippet: unknown = JSON.parse(mcpConfig(mcpUrl));

        expect(snippet).toEqual({
            mcpServers: {
                'holistic-review': {
                    type: 'http',
                    url: 'https://hr.example/_mcp',
                    headers: { Authorization: 'Bearer ${HOLISTIC_REVIEW_TOKEN}' },
                },
            },
        });
        expect(snippet).toEqual(specifiedClientConfig(mcpUrl));
    });
});
