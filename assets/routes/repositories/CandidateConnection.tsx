import { Button, Link } from '@primer/react';
import { Link as RouterLink, useFetcher } from 'react-router';
import type { ActionResult } from '../../api/actionResult';
import type { RepositoryCandidate } from '../../api/types/repository-candidate';
import { ActionErrorMessage } from '../../layout/ActionErrorMessage';
import { useSubmitOnce } from '../../layout/useSubmitOnce';
import styles from './CandidateConnection.module.css';

type Props = {
    candidate: RepositoryCandidate;
    /** The onboarding opens the repository after the connect. */
    openRepository?: boolean;
};

/**
 * The "Connection" cell of a candidate table: a link to the connected repository, else a Connect form for the route
 * action, which reads `directory` or `githubRepositoryId`, and `openRepository`.
 */
export function CandidateConnection({ candidate, openRepository = false }: Props) {
    const fetcher = useFetcher<ActionResult>();
    const submitOnce = useSubmitOnce(fetcher.submit);

    if (null !== candidate.connectedRepositoryId) {
        return (
            <Link as={RouterLink} to={`/repositories/${candidate.connectedRepositoryId}`}>
                Connected
            </Link>
        );
    }

    return (
        <fetcher.Form method="post" className={styles.connect} onSubmit={submitOnce}>
            {'github' === candidate.source ? (
                <input type="hidden" name="githubRepositoryId" value={candidate.github.repositoryId} />
            ) : (
                <input type="hidden" name="directory" value={candidate.local.directory} />
            )}
            {openRepository && <input type="hidden" name="openRepository" value="true" />}
            <Button type="submit" size="small" aria-label={`Connect ${candidate.name}`} loading={'idle' !== fetcher.state}>
                Connect
            </Button>
            <ActionErrorMessage result={fetcher.data} />
        </fetcher.Form>
    );
}
