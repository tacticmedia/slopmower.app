import { Heading } from '@primer/react';
import { CopyButton } from './CopyButton';
import { mcpConfig } from './mcpConfig';
import styles from './Settings.module.css';

export function McpConfigSnippet({ mcpUrl }: { mcpUrl: string }) {
    const snippet = mcpConfig(mcpUrl);

    return (
        <section className={styles.section} aria-labelledby="mcp-config-title">
            <div className={styles.sectionHeader}>
                <Heading as="h3" id="mcp-config-title" className={styles.sectionTitle}>
                    MCP configuration
                </Heading>
                <CopyButton text={snippet} label="Copy MCP configuration" />
            </div>
            <p className={styles.note}>
                Add this file as <code>.mcp.json</code> in the root of your repository. It contains no secret.
            </p>
            <pre className={styles.code} data-testid="mcp-config">
                <code>{snippet}</code>
            </pre>
        </section>
    );
}
