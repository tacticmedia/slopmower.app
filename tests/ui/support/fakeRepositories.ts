import type { Commit } from '../../../assets/api/types/commit';
import type { CheckEveryPullRequestInput, ConnectRepositoryInput, StartReviewInput } from '../../../assets/api/types/inputs';
import type { CandidateList } from '../../../assets/api/types/lists';
import type { Repository } from '../../../assets/api/types/repository';
import type { RepositoryCandidate } from '../../../assets/api/types/repository-candidate';
import { candidateList, commits, githubRepository, localRepository, now, sha1 } from './builders';
import { FakeReview, type FakeReviewOptions } from './fakeReview';
import { FakeTokens } from './fakeTokens';
import { type ApiCall, apiError, json, type MockApi, type MockResponse, noContent } from './mockApi';

/**
 * The repositories of the user in memory: R1 to R3, R5, R6, R8, R9, V1, and V2. Each review of a repository is a
 * {@link FakeReview}. The tokens answer T1, which the getting started steps read.
 */
export class FakeRepositories {
    readonly tokens: FakeTokens;
    repositories: Repository[] = [];
    candidates: RepositoryCandidate[] = [];
    /** The fields of R2 besides `items`. */
    candidateFields: Partial<CandidateList> = {};
    readonly reviews: FakeReview[] = [];
    /** The R9 commits of the default branch by repository ID, newest first. Without an entry, those of the fixture. */
    readonly commits = new Map<string, Commit[]>();
    /** Runs when R5 fetches the repository, as the server recomputes the locations. */
    onFetch: (repository: Repository) => void = () => undefined;
    readonly #api: MockApi;

    constructor(api: MockApi) {
        this.#api = api;
        this.tokens = new FakeTokens(api, this);
        api.on('GET', '/api/v1/repositories', () => json('lists.schema.json#/$defs/RepositoryPage', { items: this.repositories, nextCursor: null }));
        api.on('GET', '/api/v1/repositories/candidates', () => json('lists.schema.json#/$defs/CandidateList', candidateList(this.candidates, this.candidateFields)));
        api.on('POST', '/api/v1/repositories', ({ body }) => this.#connect(body as ConnectRepositoryInput));
        api.on('GET', '/api/v1/repositories/:repositoryId/reviews', (call) => this.#with(call, (repository) => this.#reviewPage(repository, call.query.get('state'))));
        api.on('POST', '/api/v1/repositories/:repositoryId/reviews', (call) => this.#with(call, (repository) => this.#startReview(repository, call.body as StartReviewInput)));
        api.on('GET', '/api/v1/repositories/:repositoryId/commits', (call) => this.#with(call, (repository) => this.#commitLog(repository, call.query)));
        api.on('POST', '/api/v1/repositories/:repositoryId/fetch', (call) =>
            this.#with(call, (repository) => {
                this.onFetch(repository);

                return json('repository.schema.json', { ...repository, sync: { ...repository.sync, state: 'fetching' } }, 202);
            }),
        );
        api.on('POST', '/api/v1/repositories/:repositoryId/check-every-pull-request', (call) =>
            this.#with(call, (repository) => {
                if (null === repository.github) {
                    return apiError(422, 'invalid_input', 'The request is invalid.', { violations: [{ field: 'enabled', message: 'Only a GitHub repository has check runs.' }] });
                }
                const github = { ...repository.github, checkEveryPullRequest: (call.body as CheckEveryPullRequestInput).enabled };
                const changed = { ...repository, github } as Repository;
                this.repositories = this.repositories.map((stored) => (stored.id === repository.id ? changed : stored));

                return json('repository.schema.json', changed);
            }),
        );
        api.on('DELETE', '/api/v1/repositories/:repositoryId', (call) =>
            this.#with(call, (repository) => {
                if (call.query.get('confirm') !== repository.name) {
                    return apiError(422, 'invalid_input', 'The confirmation does not match the repository name.', { violations: [{ field: 'confirm', message: 'Type the repository name.' }] });
                }
                this.repositories = this.repositories.filter(({ id }) => id !== repository.id);

                return noContent(202);
            }),
        );
    }

    /** A connected local repository whose clone has finished. */
    addLocal(directory: string): Repository {
        const repository = localRepository(directory);
        this.repositories.push(repository);

        return repository;
    }

    addReview(repository: Repository, options: FakeReviewOptions = {}): FakeReview {
        const review = new FakeReview(this.#api, { ...options, review: { repositoryId: repository.id, repositoryName: repository.name, ...options.review } });
        this.reviews.push(review);

        return review;
    }

    #with(call: ApiCall, handler: (repository: Repository) => MockResponse): MockResponse | undefined {
        const repository = this.repositories.find(({ id }) => id === call.params.repositoryId);

        return undefined === repository ? undefined : handler(repository);
    }

    /** The clone job that R3 queued finishes. */
    finishClone(repositoryId: string): void {
        this.repositories = this.repositories.map((repository) =>
            repository.id === repositoryId ? ({ ...repository, access: 'active', defaultBranch: 'main', sync: { state: 'idle', lastFetchedAt: now(), lastError: null, defaultBranchHead: sha1(repositoryId) } } as Repository) : repository,
        );
    }

    #connect(input: ConnectRepositoryInput): MockResponse {
        if ('local' === input.source && false === this.candidateFields.localEnabled) {
            return apiError(422, 'invalid_input', 'Local repositories are off.', { violations: [{ field: 'source', message: 'Local repositories are off.' }] });
        }
        const candidate = this.candidates.find((known) =>
            'local' === input.source ? 'local' === known.source && known.local.directory === input.directory : 'github' === known.source && known.github.repositoryId === input.githubRepositoryId,
        );
        if (undefined === candidate) {
            return apiError(404, 'not_found', 'local' === input.source ? `The directory ${input.directory} is not a local candidate.` : `The GitHub repository ${input.githubRepositoryId} is not a candidate.`);
        }
        const connected = this.repositories.find(({ id }) => id === candidate.connectedRepositoryId);
        if (undefined !== connected) {
            return json('repository.schema.json', connected);
        }
        const repository =
            'local' === candidate.source
                ? ({ ...localRepository(candidate.local.directory), access: 'active', defaultBranch: null, sync: { state: 'cloning', lastFetchedAt: null, lastError: null, defaultBranchHead: null } } as Repository)
                : githubRepository(candidate);
        this.repositories.push(repository);
        this.candidates = this.candidates.map((known) => (known === candidate ? { ...known, connectedRepositoryId: repository.id } : known));

        return json('repository.schema.json', repository, 201);
    }

    #reviewPage(repository: Repository, state: string | null): MockResponse {
        const items = this.reviews
            .filter((review) => review.review.repositoryId === repository.id && !review.deleted)
            .map((review) => review.review)
            .filter((review) => 'all' === state || 'open' === review.state);

        return json('lists.schema.json#/$defs/ReviewPage', { repository, items, nextCursor: null });
    }

    /** Only the default branch and the full SHAs of its R9 commits resolve. */
    #startReview(repository: Repository, input: StartReviewInput): MockResponse {
        const ref = input.ref ?? repository.defaultBranch ?? 'main';
        const commit = this.#commitsOf(repository).find(({ sha }) => sha === ref);
        if (ref !== repository.defaultBranch && undefined === commit) {
            return apiError(422, 'commit_not_found', `The commit ${ref} is not in the server clone.`, { commit: ref });
        }
        const review = this.addReview(repository, { review: { ref, createdAt: now(), ...(undefined === commit ? {} : { commitSha: commit.sha }) } });
        const shortSha = review.review.commitSha.slice(0, 12);
        review.review = { ...review.review, title: input.title ?? (undefined === commit ? `${ref} @ ${shortSha}` : shortSha) };

        return json('review.schema.json', review.review, 201);
    }

    /** 409 while cloning. Only the default branch and the full SHAs of its commits resolve. */
    #commitLog(repository: Repository, query: URLSearchParams): MockResponse {
        if ('cloning' === repository.sync.state) {
            return apiError(409, 'repository_unavailable', `The repository ${repository.name} is cloning.`, { repositoryId: repository.id, reason: 'cloning' });
        }
        const ref = query.get('ref') ?? repository.defaultBranch;
        if (null === ref) {
            return apiError(422, 'invalid_input', 'The repository has no default branch. Name a ref.', { violations: [{ field: 'ref', message: 'The repository has no default branch. Name a ref.' }] });
        }
        const all = this.#commitsOf(repository);
        const start = ref === repository.defaultBranch ? 0 : all.findIndex(({ sha }) => sha === ref);
        if (-1 === start) {
            return apiError(422, 'commit_not_found', `The commit ${ref} is not in the server clone.`, { commit: ref });
        }

        return json('lists.schema.json#/$defs/CommitLog', { repositoryId: repository.id, ref, items: all.slice(start, start + Number(query.get('limit') ?? 10)) });
    }

    #commitsOf(repository: Repository): Commit[] {
        return this.commits.get(repository.id) ?? commits();
    }
}
