import { Heading } from '@primer/react';
import { type ActionFunctionArgs, type LoaderFunctionArgs, redirect, useLoaderData, useRouteLoaderData } from 'react-router';
import { actionError, type ActionResult, writeResult } from '../../api/actionResult';
import { apiGet, apiGetAllPages, apiWrite } from '../../api/client';
import type { ApiToken } from '../../api/types/api-token';
import type { ConnectRepositoryInput } from '../../api/types/inputs';
import type { CandidateList, RepositoryPage, TokenList } from '../../api/types/lists';
import type { Me } from '../../api/types/me';
import type { Repository } from '../../api/types/repository';
import { ConnectRepositoryActions } from '../../onboarding/ConnectRepositoryActions';
import { OnboardingSteps } from '../../onboarding/OnboardingSteps';
import { ConnectedRepositories } from './ConnectedRepositories';
import { type GitHubCandidate, GitHubCandidates } from './GitHubCandidates';
import { type LocalCandidate, LocalCandidates } from './LocalCandidates';
import styles from './RepositoriesRoute.module.css';

type RepositoriesData = {
    repositories: Repository[];
    candidates: CandidateList;
    /** T1, read only while no repository is connected: the onboarding shows then. */
    tokens: ApiToken[] | null;
};

export async function allRepositories(signal: AbortSignal): Promise<Repository[]> {
    return (await apiGetAllPages<RepositoryPage>('/api/v1/repositories?limit=500', signal)).items;
}

export async function repositoriesLoader({ request }: LoaderFunctionArgs): Promise<RepositoriesData> {
    const [{ repositories, tokens }, candidates] = await Promise.all([
        allRepositories(request.signal).then(async (repositories) => ({
            repositories,
            tokens: 0 === repositories.length ? (await apiGet<TokenList>('/api/v1/tokens', request.signal)).items : null,
        })),
        apiGet<CandidateList>('/api/v1/repositories/candidates', request.signal),
    ]);

    return { repositories, candidates, tokens };
}

/** A connect from the onboarding opens the repository. A connect from the list keeps the list. */
export async function repositoriesAction({ request }: ActionFunctionArgs): Promise<ActionResult | Response> {
    const form = await request.formData();
    const githubRepositoryId = form.get('githubRepositoryId');
    const input: ConnectRepositoryInput =
        null === githubRepositoryId
            ? { source: 'local', directory: String(form.get('directory')) }
            : { source: 'github', githubRepositoryId: Number(githubRepositoryId) };
    const connect = () => apiWrite<Repository>('POST', '/api/v1/repositories', input);

    if (null === form.get('openRepository')) {
        return writeResult(connect);
    }
    try {
        return redirect(`/repositories/${(await connect()).id}`);
    } catch (error) {
        return actionError(error);
    }
}

export function RepositoriesRoute() {
    const { repositories, candidates, tokens } = useLoaderData<RepositoriesData>();
    const me = useRouteLoaderData<Me>('root');
    if (undefined === me) {
        throw new Error('The root route has no data.');
    }
    if (null !== tokens) {
        return (
            <div className={styles.welcome}>
                <header className={styles.intro}>
                    <Heading as="h1">Welcome to Slopmower</Heading>
                    <p className={styles.lead}>Review a whole codebase at one commit. Each comment becomes a change request that your coding agent picks up.</p>
                </header>
                <OnboardingSteps form="full" tokens={tokens} actions={<ConnectRepositoryActions features={me.features} candidates={candidates} />} />
            </div>
        );
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
            {me.features.localRepositories && <LocalCandidates candidates={localCandidates} />}
            {candidates.truncated && <p className={styles.note}>The list shows the first 1,000 candidates.</p>}
        </div>
    );
}
