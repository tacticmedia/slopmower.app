import { CopyButton } from '../layout/CopyButton';
import { HelpLink } from '../layout/HelpLink';
import { HELP } from '../layout/helpLinks';
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
                <HelpLink href={HELP.connectYourAgent}>Connect your agent</HelpLink>
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
