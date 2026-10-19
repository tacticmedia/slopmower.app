import { Link } from '@primer/react';
import { type Column, DataTable, Table } from '@primer/react/experimental';
import { Link as RouterLink } from 'react-router';
import type { Repository } from '../../api/types/repository';
import { SyncStatus } from './SyncStatus';

export const SOURCE_NAMES: Record<Repository['source'], string> = { github: 'GitHub', local: 'Local' };

const COLUMNS: Column<Repository>[] = [
    {
        id: 'name',
        header: 'Name',
        rowHeader: true,
        renderCell: (repository) => (
            <Link as={RouterLink} to={`/repositories/${repository.id}`}>
                {repository.name}
            </Link>
        ),
    },
    { id: 'source', header: 'Source', width: 'auto', renderCell: (repository) => SOURCE_NAMES[repository.source] },
    { id: 'defaultBranch', header: 'Default branch', renderCell: (repository) => repository.defaultBranch ?? '-' },
    { id: 'sync', header: 'Sync', renderCell: (repository) => <SyncStatus sync={repository.sync} /> },
    { id: 'openReviewCount', header: 'Open reviews', width: 'auto', align: 'end', renderCell: (repository) => repository.openReviewCount },
];

/** Without a repository, the onboarding shows in place of this table. */
export function ConnectedRepositories({ repositories }: { repositories: Repository[] }) {
    return (
        <Table.Container data-testid="connected-repositories">
            <Table.Title as="h2" id="connected-repositories">
                Connected repositories
            </Table.Title>
            <DataTable aria-labelledby="connected-repositories" data={repositories} columns={COLUMNS} />
        </Table.Container>
    );
}
