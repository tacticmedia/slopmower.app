import { Button, Label, Link, RelativeTime } from '@primer/react';
import { LinkExternalIcon, SyncIcon } from '@primer/octicons-react';
import { type Column, DataTable, Table } from '@primer/react/experimental';
import type { RepositoryCandidate } from '../../api/types/repository-candidate';
import { CandidateConnection } from './CandidateConnection';

export type GitHubCandidate = Extract<RepositoryCandidate, { source: 'github' }>;

type CandidateRow = { id: number; candidate: GitHubCandidate };

const COLUMNS: Column<CandidateRow>[] = [
    {
        id: 'name',
        header: 'Repository',
        rowHeader: true,
        renderCell: ({ candidate }) => (
            <Link href={candidate.github.htmlUrl} target="_blank" rel="noreferrer">
                {candidate.name}
            </Link>
        ),
    },
    {
        id: 'visibility',
        header: 'Visibility',
        width: 'auto',
        renderCell: ({ candidate }) => <Label variant="secondary">{candidate.github.private ? 'Private' : 'Public'}</Label>,
    },
    { id: 'branch', header: 'Default branch', renderCell: ({ candidate }) => <code>{candidate.github.defaultBranch}</code> },
    { id: 'account', header: 'Installed on', renderCell: ({ candidate }) => candidate.github.accountLogin },
    { id: 'action', header: 'Connection', renderCell: ({ candidate }) => <CandidateConnection candidate={candidate} /> },
];

/** The setup URL signs in again, which lists the repositories again (R2). */
export function GitHubRefreshButton() {
    return (
        <Button as="a" href="/github/installed" size="small" leadingVisual={SyncIcon}>
            Refresh
        </Button>
    );
}

type GitHubCandidatesProps = {
    candidates: GitHubCandidate[];
    configureUrl: string;
    listedAt: string | null;
};

export function GitHubCandidates({ candidates, configureUrl, listedAt }: GitHubCandidatesProps) {
    return (
        <Table.Container data-testid="github-candidates">
            <Table.Title as="h2" id="github-candidates">
                GitHub repositories
            </Table.Title>
            <Table.Subtitle as="p" id="github-candidates-subtitle">
                {null === listedAt ? (
                    'The GitHub App has access to no repository of yours.'
                ) : (
                    <>
                        Listed <RelativeTime datetime={listedAt} /> at your sign-in.
                    </>
                )}
            </Table.Subtitle>
            <Table.Actions>
                <GitHubRefreshButton />
                <Button as="a" href={configureUrl} size="small" trailingVisual={LinkExternalIcon} data-testid="github-configure">
                    Configure on GitHub
                </Button>
            </Table.Actions>
            {0 < candidates.length && (
                <DataTable
                    aria-labelledby="github-candidates"
                    aria-describedby="github-candidates-subtitle"
                    data={candidates.map((candidate) => ({ id: candidate.github.repositoryId, candidate }))}
                    columns={COLUMNS}
                />
            )}
        </Table.Container>
    );
}
