import { Link } from '@primer/react';
import { CopyButton } from '../layout/CopyButton';
import styles from './CopyField.module.css';
import { agentPrompt, suggestionPrompt } from './promptSnippet';

export function AgentPromptSnippet({ reviewId }: { reviewId: string }) {
    const prompt = agentPrompt(reviewId);
    const reviewPrompt = suggestionPrompt(reviewId);

    return (
        <>
            <div className={styles.field}>
                <span className={styles.label}>Agent prompt</span>
                <code className={styles.value} data-testid="agent-prompt">
                    {prompt}
                </code>
                <CopyButton text={prompt} label="Copy prompt" />
                <Link href="/docs/connect-your-agent">Connect your agent</Link>
            </div>
            <div className={styles.field}>
                <span className={styles.label}>Review prompt</span>
                <code className={styles.value} data-testid="suggestion-prompt">
                    {reviewPrompt}
                </code>
                <CopyButton text={reviewPrompt} label="Copy review prompt" />
            </div>
        </>
    );
}
