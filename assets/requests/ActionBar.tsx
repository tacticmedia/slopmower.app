import { Button } from '@primer/react';
import { useRef } from 'react';
import { Link as RouterLink } from 'react-router';
import { useStuck } from '../layout/useStuck';
import type { RequestDetailData } from '../routes/requests/requestData';
import styles from './ActionBar.module.css';
import { requestButtons, type SuggestionButton, suggestionButtons } from './allowedActions';
import { RequestActions } from './RequestActions';
import { StatusLabel } from './StatusLabel';
import { SuggestionActions } from './SuggestionActions';

type Props = Pick<RequestDetailData, 'id' | 'status' | 'summary' | 'allowedActions' | 'nextRequestId'>;

/**
 * The primary actions of the request detail. A bar without a button, for example in a closed review, is left out.
 */
export function ActionBar(props: Props) {
    const suggestion = suggestionButtons(props.allowedActions);

    return 0 === requestButtons(props.allowedActions).length + suggestion.length ? null : <StickyBar {...props} suggestion={suggestion} />;
}

/** The parent element must span the page, so that the bar sticks to the window top for the whole page. */
function StickyBar({ id, status, summary, allowedActions, nextRequestId, suggestion }: Props & { suggestion: SuggestionButton[] }) {
    const sentinel = useRef<HTMLDivElement>(null);
    const stuck = useStuck(sentinel);

    return (
        <>
            <div ref={sentinel} className={styles.sentinel} />
            <div className={styles.bar} data-testid="action-bar">
                {stuck && (
                    <div className={styles.context}>
                        <StatusLabel status={status} testId="stuck-status" />
                        <span className={styles.summary} title={summary}>
                            {summary}
                        </span>
                    </div>
                )}
                <div className={styles.actions}>
                    <RequestActions key={id} allowedActions={allowedActions} />
                    <SuggestionActions key={`suggestion-${id}`} requestId={id} buttons={suggestion} detail />
                    {null !== nextRequestId && (
                        <Button as={RouterLink} to={`/requests/${nextRequestId}`}>
                            Next request
                        </Button>
                    )}
                </div>
            </div>
        </>
    );
}
