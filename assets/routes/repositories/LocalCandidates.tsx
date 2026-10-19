import { type Column, DataTable, Table } from '@primer/react/experimental';
import type { RepositoryCandidate } from '../../api/types/repository-candidate';
import { CandidateConnection } from './CandidateConnection';

export type LocalCandidate = Extract<RepositoryCandidate, { source: 'local' }>;

type CandidateRow = { id: string; candidate: LocalCandidate };

const COLUMNS: Column<CandidateRow>[] = [
    { id: 'name', header: 'Directory', rowHeader: true, renderCell: ({ candidate }) => candidate.name },
    { id: 'branch', header: 'HEAD branch', renderCell: ({ candidate }) => candidate.local.headBranch ?? 'detached' },
    { id: 'bare', header: 'Bare', width: 'auto', renderCell: ({ candidate }) => (candidate.local.bare ? 'Yes' : 'No') },
    { id: 'action', header: 'Connection', renderCell: ({ candidate }) => <CandidateConnection candidate={candidate} /> },
];

export function LocalCandidates({ candidates }: { candidates: LocalCandidate[] }) {
    return (
        <Table.Container data-testid="local-candidates">
            <Table.Title as="h2" id="local-candidates">
                Local directories
            </Table.Title>
            {0 === candidates.length ? (
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
