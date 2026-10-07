import { Button, Heading, Label, Link } from '@primer/react';
import { SyncIcon } from '@primer/octicons-react';
import { useFetcher } from 'react-router';
import type { ActionResult } from '../../api/actionResult';
import type { Repository } from '../../api/types/repository';
import { ActionErrorMessage } from '../../layout/ActionErrorMessage';
import { useSubmitOnce } from '../../layout/useSubmitOnce';
import { SOURCE_NAMES } from './ConnectedRepositories';
import styles from './RepositoryRoute.module.css';
import { SyncStatus } from './SyncStatus';

export function RepositoryHeader({ repository }: { repository: Repository }) {
    const refresh = useFetcher<ActionResult>();
    const submitOnce = useSubmitOnce(refresh.submit);

    return (
        <header className={styles.header}>
            <div className={styles.headerText}>
                <Heading as="h1">{repository.name}</Heading>
                <span className={styles.meta}>
                    {SOURCE_NAMES[repository.source]} repository, default branch{' '}
                    <code>{repository.defaultBranch ?? 'unknown'}</code>
                </span>
                {null !== repository.github && (
                    <span className={styles.github} data-testid="github-details">
                        <Label variant="secondary">{repository.github.private ? 'Private' : 'Public'}</Label>
                        <span className={styles.meta}>Owner {repository.github.owner}</span>
                        <Link href={repository.github.htmlUrl} target="_blank" rel="noreferrer">
                            View on GitHub
                        </Link>
                    </span>
                )}
                <SyncStatus sync={repository.sync} />
            </div>
            <refresh.Form method="post" className={styles.actions} onSubmit={submitOnce}>
                <input type="hidden" name="intent" value="refresh" />
                <Button type="submit" leadingVisual={SyncIcon} loading={'idle' !== refresh.state}>
                    Refresh
                </Button>
                <ActionErrorMessage result={refresh.data} />
            </refresh.Form>
        </header>
    );
}
