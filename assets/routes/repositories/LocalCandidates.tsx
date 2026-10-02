import { Banner, Button, Link } from '@primer/react';
import { type Column, DataTable, Table } from '@primer/react/experimental';
import { Link as RouterLink, useFetcher } from 'react-router';
import type { ActionResult } from '../../api/actionResult';
import type { RepositoryCandidate } from '../../api/types/repository-candidate';
import { ActionErrorMessage } from '../../layout/ActionErrorMessage';
import styles from './LocalCandidates.module.css';

export type LocalCandidate = Extract<RepositoryCandidate, { source: 'local' }>;

type CandidateRow = { id: string; candidate: LocalCandidate };

const COLUMNS: Column<CandidateRow>[] = [
    { id: 'name', header: 'Directory', rowHeader: true, renderCell: ({ candidate }) => candidate.name },
    { id: 'branch', header: 'HEAD branch', renderCell: ({ candidate }) => candidate.local.headBranch ?? 'detached' },
    { id: 'bare', header: 'Bare', width: 'auto', renderCell: ({ candidate }) => (candidate.local.bare ? 'Yes' : 'No') },
    { id: 'action', header: 'Connection', renderCell: ({ candidate }) => <Connection candidate={candidate} /> },
];

function Connection({ candidate }: { candidate: LocalCandidate }) {
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
            <input type="hidden" name="directory" value={candidate.local.directory} />
            <Button type="submit" size="small" aria-label={`Connect ${candidate.name}`} loading={'idle' !== fetcher.state}>
                Connect
            </Button>
            <ActionErrorMessage result={fetcher.data} />
        </fetcher.Form>
    );
}

export function LocalCandidates({ enabled, candidates }: { enabled: boolean; candidates: LocalCandidate[] }) {
    return (
        <Table.Container data-testid="local-candidates">
            <Table.Title as="h2" id="local-candidates">
                Local directories
            </Table.Title>
            {!enabled ? (
                <Banner
                    variant="info"
                    title="Local repositories are off"
                    description="This instance does not connect local directories. The operator turns them on with APP_LOCAL_REPOSITORIES_DIR."
                    data-testid="local-repositories-off"
                />
            ) : 0 === candidates.length ? (
                <Table.Subtitle as="p" id="local-candidates-empty">
                    The local repositories directory contains no git repository.
                </Table.Subtitle>
            ) : (
                <DataTable
                    aria-labelledby="local-candidates"
                    data={candidates.map((candidate) => ({ id: candidate.name, candidate }))}
                    columns={COLUMNS}
                />
            )}
        </Table.Container>
    );
}
