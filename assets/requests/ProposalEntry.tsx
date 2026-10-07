import { DownloadIcon } from '@primer/octicons-react';
import { Button, Label, Link, Spinner } from '@primer/react';
import { InlineMessage } from '@primer/react/experimental';
import { lazy, Suspense } from 'react';
import { Link as RouterLink } from 'react-router';
import type { Ulid } from '../api/types/common';
import type { ProposalView } from '../comments/threadView';
import { usePreference } from '../layout/usePreference';
import { NoteDialog } from './NoteDialog';
import styles from './Requests.module.css';
import { useRequestCommand } from './useRequestCommand';

// A separate chunk, so the diff library loads with the first diff.
const ProposalDiff = lazy(() => import('../code/CommitDiff').then(({ ProposalDiff }) => ({ default: ProposalDiff })));

const STATE_LABELS: Record<ProposalView['state'], { text: string; variant: 'attention' | 'success' | 'danger' | 'secondary' }> = {
    pending: { text: 'Waiting for approval', variant: 'attention' },
    approved: { text: 'Approved', variant: 'success' },
    rejected: { text: 'Changes requested', variant: 'danger' },
    superseded: { text: 'Superseded', variant: 'secondary' },
    withdrawn: { text: 'Withdrawn', variant: 'secondary' },
};

type Props = {
    proposal: ProposalView;
    /** Null until Q2 answers. */
    requestId: Ulid | null;
    /** The caller may approve or reject the pending proposal. */
    decidable: boolean;
    /** On the request detail: the diff and the decision. Elsewhere a link to the request detail. */
    detail: boolean;
};

/**
 * The diff and the decision of a `request.proposed` entry, below its plan.
 */
export function ProposalEntry({ proposal, requestId, decidable, detail }: Props) {
    const { diff, state } = proposal;
    const label = STATE_LABELS[state];

    return (
        <div className={styles.proposal} data-testid="proposal" data-event-id={proposal.eventId}>
            {detail && null !== diff && <ProposalDiffSection diff={diff} cacheKey={proposal.eventId} fileName={`${requestId ?? 'request'}-${proposal.seq}.diff`} />}
            <div className={styles.proposalFooter}>
                {detail && decidable && 'pending' === state ? (
                    <ProposalDecision proposalEventId={proposal.eventId} />
                ) : (
                    <Label variant={label.variant} data-testid="proposal-state">
                        {label.text}
                    </Label>
                )}
                {!detail && null !== requestId && (
                    <Link as={RouterLink} to={`/requests/${requestId}`}>
                        Open the proposal
                    </Link>
                )}
            </div>
        </div>
    );
}

function ProposalDiffSection({ diff, cacheKey, fileName }: { diff: string; cacheKey: string; fileName: string }) {
    const [wrap] = usePreference('wrap');

    return (
        <section className={styles.proposalDiff} aria-label="Proposed diff">
            <Button size="small" leadingVisual={DownloadIcon} onClick={() => download(diff, fileName)} className={styles.download}>
                Download .diff
            </Button>
            <Suspense
                fallback={
                    <p className={styles.muted}>
                        <Spinner size="small" srText={null} /> Loading the diff
                    </p>
                }
            >
                <ProposalDiff diff={diff} cacheKey={cacheKey} overflow={wrap ? 'wrap' : 'scroll'} />
            </Suspense>
        </section>
    );
}

function download(diff: string, fileName: string): void {
    const url = URL.createObjectURL(new Blob([diff], { type: 'text/x-diff' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = fileName;
    link.click();
    // After the click hands the URL to the download.
    setTimeout(() => URL.revokeObjectURL(url));
}

/**
 * "Approve" sends Q15 at once, "Request changes" sends Q16 with a required note.
 */
function ProposalDecision({ proposalEventId }: { proposalEventId: Ulid }) {
    const { busy, sent, error, dialog, opener, send, openDialog, closeDialog } = useRequestCommand<'reject_proposal'>();

    return (
        <div className={styles.proposalDecision} data-testid="proposal-decision">
            <div className={styles.buttons}>
                <Button variant="primary" onClick={() => send({ intent: 'approve_proposal', input: { proposalEventId } })} loading={'approve_proposal' === sent} disabled={busy}>
                    Approve
                </Button>
                <Button onClick={(event) => openDialog('reject_proposal', event)} disabled={busy}>
                    Request changes
                </Button>
            </div>
            {null === dialog && null !== error && (
                <InlineMessage variant="critical" role="alert" data-testid="action-error">
                    {error}
                </InlineMessage>
            )}
            {null !== dialog && (
                <NoteDialog
                    title="Request changes"
                    subtitle="The agent proposes again. Say what to change."
                    fieldLabel="Note"
                    rule="required"
                    submitLabel="Request changes"
                    buttonType="primary"
                    busy={busy}
                    error={error}
                    onSubmit={(note) => send({ intent: 'reject_proposal', input: { proposalEventId, note } })}
                    onClose={closeDialog}
                    returnFocusRef={opener}
                />
            )}
        </div>
    );
}
