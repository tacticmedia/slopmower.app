export const TOKEN_ENVIRONMENT_VARIABLE = 'HOLISTIC_REVIEW_TOKEN';

/**
 * The `.mcp.json` client configuration of docs/specs/mcp.md section 1. The agent expands the variable, so the file holds no secret.
 */
export function mcpConfig(mcpUrl: string): string {
    const config = {
        mcpServers: {
            'holistic-review': {
                type: 'http',
                url: mcpUrl,
                headers: { Authorization: `Bearer \${${TOKEN_ENVIRONMENT_VARIABLE}}` },
            },
        },
    };

    return JSON.stringify(config, null, 2);
}
