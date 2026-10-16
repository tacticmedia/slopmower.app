import { TerminalIcon } from '@primer/octicons-react';
import { AnchoredOverlay, Button } from '@primer/react';
import { useState } from 'react';
import { AgentPromptSnippet } from './AgentPromptSnippet';
import styles from './AgentPromptsOverlay.module.css';
import { ReviewIdCopy } from './ReviewIdCopy';

export function AgentPromptsOverlay({ reviewId }: { reviewId: string }) {
    const [open, setOpen] = useState(false);

    return (
        <AnchoredOverlay
            open={open}
            onOpen={() => setOpen(true)}
            onClose={() => setOpen(false)}
            renderAnchor={(anchorProps) => (
                <Button {...anchorProps} leadingVisual={TerminalIcon}>
                    Agent prompts
                </Button>
            )}
            width="large"
            align="end"
            // The default focus zone is for menus: it takes the copy buttons and the link out of the Tab order.
            focusZoneSettings={{ disabled: true }}
            overlayProps={{ role: 'dialog', 'aria-label': 'Agent prompts' }}
        >
            <div className={styles.prompts} data-testid="agent-prompts">
                <ReviewIdCopy reviewId={reviewId} />
                <AgentPromptSnippet reviewId={reviewId} />
            </div>
        </AnchoredOverlay>
    );
}
