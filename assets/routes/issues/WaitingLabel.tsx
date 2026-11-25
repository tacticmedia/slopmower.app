import { Label } from '@primer/react';
import { useRouteLoaderData } from 'react-router';
import type { IssueParty, IssueSummary } from '../../api/types/issue';
import type { Me } from '../../api/types/me';

type Parties = Pick<IssueSummary, 'requester' | 'assignee' | 'waitingFor'>;

/** The party that `waitingFor` names (D134). Null in a status where nobody has the next step. */
export function waitingParty({ requester, assignee, waitingFor }: Parties): IssueParty | null {
    if (null === waitingFor) {
        return null;
    }

    return 'requester' === waitingFor ? requester : assignee;
}

export function partyName(party: IssueParty): string {
    return 'agent' === party.kind ? 'the agent' : (party.name ?? 'a user');
}

/** The signed-in user. */
export function useIsYou(): (party: IssueParty | null) => boolean {
    const userId = useRouteLoaderData<Me>('root')?.user.id;

    return (party) => 'user' === party?.kind && party.userId === userId;
}

/** "Waiting for {login}" or "Waiting for the agent", in the attention colour when it names the signed-in user. */
export function WaitingLabel({ issue }: { issue: Parties }) {
    const isYou = useIsYou();
    const party = waitingParty(issue);

    return null === party ? null : (
        <Label variant={isYou(party) ? 'attention' : 'secondary'} data-testid="waiting-label">
            Waiting for {partyName(party)}
        </Label>
    );
}
