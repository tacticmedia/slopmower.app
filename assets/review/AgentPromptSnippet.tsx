import { CopyButton } from '../layout/CopyButton';
import styles from './CopyField.module.css';
import { agentPrompt } from './promptSnippet';

export function AgentPromptSnippet({ reviewId }: { reviewId: string }) {
    const prompt = agentPrompt(reviewId);

    return (
        <div className={styles.field}>
            <span className={styles.label}>Agent prompt</span>
            <code className={styles.value} data-testid="agent-prompt">
                {prompt}
            </code>
            <CopyButton text={prompt} label="Copy prompt" />
        </div>
    );
}
