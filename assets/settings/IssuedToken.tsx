import { Banner, Heading } from '@primer/react';
import type { ApiTokenWithSecret } from '../api/types/api-token';
import { CopyButton } from '../layout/CopyButton';
import { McpConfigSnippet } from './McpConfigSnippet';
import { TOKEN_ENVIRONMENT_VARIABLE } from './mcpConfig';
import styles from './Settings.module.css';

type Props = {
    token: ApiTokenWithSecret;
    mcpUrl: string;
};

export function IssuedToken({ token, mcpUrl }: Props) {
    return (
        <>
            <Banner variant="warning" title="Copy the token now" description="Slopmower shows it only once." />
            <section className={styles.section} aria-labelledby="token-secret-title">
                <div className={styles.sectionHeader}>
                    <Heading as="h3" id="token-secret-title" className={styles.sectionTitle}>
                        Token for {token.name}
                    </Heading>
                    <CopyButton text={token.secret} label="Copy token" />
                </div>
                <code className={styles.secret} data-testid="token-secret">
                    {token.secret}
                </code>
                <p className={styles.note}>
                    Put the token into the environment variable <code>{TOKEN_ENVIRONMENT_VARIABLE}</code> in the shell that starts your
                    agent. Do not put it into a file that you commit.
                </p>
            </section>
            <McpConfigSnippet mcpUrl={mcpUrl} />
        </>
    );
}
