import { Heading } from '@primer/react';
import { type ActionFunctionArgs, type LoaderFunctionArgs, useLoaderData, useRouteLoaderData } from 'react-router';
import { type ActionResult, writeResult } from '../../api/actionResult';
import { apiGet, apiGetAllPages, apiWrite } from '../../api/client';
import type { ConnectRepositoryInput } from '../../api/types/inputs';
import type { CandidateList, RepositoryPage } from '../../api/types/lists';
import type { Me } from '../../api/types/me';
import type { Repository } from '../../api/types/repository';
import { ConnectedRepositories } from './ConnectedRepositories';
import { type LocalCandidate, LocalCandidates } from './LocalCandidates';
import styles from './RepositoriesRoute.module.css';

type RepositoriesData = {
    repositories: Repository[];
    candidates: CandidateList;
};

export async function repositoriesLoader({ request }: LoaderFunctionArgs): Promise<RepositoriesData> {
    const [page, candidates] = await Promise.all([
        apiGetAllPages<RepositoryPage>('/api/v1/repositories?limit=500', request.signal),
        apiGet<CandidateList>('/api/v1/repositories/candidates', request.signal),
    ]);

    return { repositories: page.items, candidates };
}

export async function repositoriesAction({ request }: ActionFunctionArgs): Promise<ActionResult> {
    const form = await request.formData();
    const input: ConnectRepositoryInput = { source: 'local', directory: String(form.get('directory')) };

    return writeResult(() => apiWrite('POST', '/api/v1/repositories', input));
}

export function RepositoriesRoute() {
    const { repositories, candidates } = useLoaderData<RepositoriesData>();
    const me = useRouteLoaderData<Me>('root');
    if (undefined === me) {
        throw new Error('The root route has no data.');
    }
    const localCandidates = candidates.items.filter((candidate): candidate is LocalCandidate => 'local' === candidate.source);

    return (
        <div className={styles.page}>
            <Heading as="h1">Repositories</Heading>
            <ConnectedRepositories repositories={repositories} />
            <LocalCandidates enabled={me.features.localRepositories} candidates={localCandidates} />
            {candidates.truncated && <p className={styles.note}>The list shows the first 1,000 candidates.</p>}
        </div>
    );
}
