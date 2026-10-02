import { Button, Link } from '@primer/react';
import { Link as RouterLink, useFetcher } from 'react-router';
import type { ActionResult } from '../../api/actionResult';
import type { RepositoryCandidate } from '../../api/types/repository-candidate';
import { ActionErrorMessage } from '../../layout/ActionErrorMessage';
import styles from './CandidateConnection.module.css';

/**
 * The "Connection" cell of a candidate table: a link to the connected repository, else a Connect form for the route
 * action, which reads `directory` or `githubRepositoryId`.
 */
export function CandidateConnection({ candidate }: { candidate: RepositoryCandidate }) {
    const fetcher = useFetcher<ActionResult>();

    if (null !== candidate.connectedRepositoryId) {
        return (
            <Link as={RouterLink} to={`/repositories/${candidate.connectedRepositoryId}`}>
                Connected
            </Link>
        );
    }

    return (
        <fetcher.Form method="post" className={styles.connect}>
            {'github' === candidate.source ? (
                <input type="hidden" name="githubRepositoryId" value={candidate.github.repositoryId} />
            ) : (
                <input type="hidden" name="directory" value={candidate.local.directory} />
            )}
            <Button type="submit" size="small" aria-label={`Connect ${candidate.name}`} loading={'idle' !== fetcher.state}>
                Connect
            </Button>
            <ActionErrorMessage result={fetcher.data} />
        </fetcher.Form>
    );
}
