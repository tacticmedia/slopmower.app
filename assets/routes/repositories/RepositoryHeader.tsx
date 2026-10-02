import { Button, Heading } from '@primer/react';
import { SyncIcon } from '@primer/octicons-react';
import { useFetcher } from 'react-router';
import type { ActionResult } from '../../api/actionResult';
import type { Repository } from '../../api/types/repository';
import { ActionErrorMessage } from '../../layout/ActionErrorMessage';
import styles from './RepositoryRoute.module.css';
import { SyncStatus } from './SyncStatus';

export function RepositoryHeader({ repository }: { repository: Repository }) {
    const refresh = useFetcher<ActionResult>();

    return (
        <header className={styles.header}>
            <div className={styles.headerText}>
                <Heading as="h1">{repository.name}</Heading>
                <span className={styles.meta}>
                    {'github' === repository.source ? 'GitHub' : 'Local'} repository, default branch{' '}
                    <code>{repository.defaultBranch ?? 'unknown'}</code>
                </span>
                <SyncStatus sync={repository.sync} />
            </div>
            <refresh.Form method="post" className={styles.actions}>
                <input type="hidden" name="intent" value="refresh" />
                <Button type="submit" leadingVisual={SyncIcon} loading={'idle' !== refresh.state}>
                    Refresh
                </Button>
                <ActionErrorMessage result={refresh.data} />
            </refresh.Form>
        </header>
    );
}
