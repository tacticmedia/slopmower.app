import { Heading } from '@primer/react';
import { type ActionFunctionArgs, type LoaderFunctionArgs, useLoaderData, useRouteLoaderData } from 'react-router';
import { type ActionResult, writeResult } from '../../api/actionResult';
import { apiGet, apiGetAllPages, apiWrite } from '../../api/client';
import type { ConnectRepositoryInput } from '../../api/types/inputs';
import type { CandidateList, RepositoryPage } from '../../api/types/lists';
import type { Me } from '../../api/types/me';
import type { Repository } from '../../api/types/repository';
import { ConnectedRepositories } from './ConnectedRepositories';
import { type GitHubCandidate, GitHubCandidates } from './GitHubCandidates';
import { type LocalCandidate, LocalCandidates } from './LocalCandidates';
import styles from './RepositoriesRoute.module.css';

type RepositoriesData = {
    repositories: Repository[];
    candidates: CandidateList;
};

export async function allRepositories(signal: AbortSignal): Promise<Repository[]> {
    return (await apiGetAllPages<RepositoryPage>('/api/v1/repositories?limit=500', signal)).items;
}

export async function repositoriesLoader({ request }: LoaderFunctionArgs): Promise<RepositoriesData> {
    const [repositories, candidates] = await Promise.all([allRepositories(request.signal), apiGet<CandidateList>('/api/v1/repositories/candidates', request.signal)]);

    return { repositories, candidates };
}

export async function repositoriesAction({ request }: ActionFunctionArgs): Promise<ActionResult> {
    const form = await request.formData();
    const githubRepositoryId = form.get('githubRepositoryId');
    const input: ConnectRepositoryInput =
        null === githubRepositoryId
            ? { source: 'local', directory: String(form.get('directory')) }
            : { source: 'github', githubRepositoryId: Number(githubRepositoryId) };

    return writeResult(() => apiWrite('POST', '/api/v1/repositories', input));
}

export function RepositoriesRoute() {
    const { repositories, candidates } = useLoaderData<RepositoriesData>();
    const me = useRouteLoaderData<Me>('root');
    if (undefined === me) {
        throw new Error('The root route has no data.');
    }
    const githubCandidates = candidates.items.filter((candidate): candidate is GitHubCandidate => 'github' === candidate.source);
    const localCandidates = candidates.items.filter((candidate): candidate is LocalCandidate => 'local' === candidate.source);

    return (
        <div className={styles.page}>
            <Heading as="h1">Repositories</Heading>
            <ConnectedRepositories repositories={repositories} />
            {null !== candidates.githubConfigureUrl && (
                <GitHubCandidates candidates={githubCandidates} configureUrl={candidates.githubConfigureUrl} listedAt={candidates.githubListedAt} />
            )}
            <LocalCandidates enabled={me.features.localRepositories} candidates={localCandidates} />
            {candidates.truncated && <p className={styles.note}>The list shows the first 1,000 candidates.</p>}
        </div>
    );
}
