import { VisuallyHidden } from '@primer/react';
import { type Column, DataTable } from '@primer/react/experimental';
import type { Repository } from '../api/types/repository';
import { SOURCE_NAMES } from '../routes/repositories/ConnectedRepositories';
import { DeleteRepositoryDialog } from './DeleteRepositoryDialog';
import styles from './Settings.module.css';

type Props = {
    repositories: Repository[];
    labelledBy: string;
    describedBy: string;
};

export function RepositoryDataTable({ repositories, labelledBy, describedBy }: Props) {
    const columns: Column<Repository>[] = [
        { header: 'Repository', id: 'name', rowHeader: true, renderCell: (repository) => repository.name },
        { header: 'Source', id: 'source', renderCell: (repository) => SOURCE_NAMES[repository.source] },
        { header: 'Open reviews', id: 'openReviewCount', renderCell: (repository) => repository.openReviewCount },
        {
            header: () => <VisuallyHidden>Actions</VisuallyHidden>,
            id: 'actions',
            align: 'end',
            renderCell: (repository) => <DeleteRepositoryDialog repository={repository} />,
        },
    ];

    return 0 === repositories.length ? (
        <p className={styles.emptyTable}>No repositories connected.</p>
    ) : (
        <DataTable aria-labelledby={labelledBy} aria-describedby={describedBy} data={repositories} columns={columns} />
    );
}
