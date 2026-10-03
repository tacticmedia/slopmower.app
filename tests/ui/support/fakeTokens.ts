import type { ApiToken } from '../../../assets/api/types/api-token';
import type { CreateTokenInput } from '../../../assets/api/types/inputs';
import { now, token, tokenSecret } from './builders';
import type { FakeRepositories } from './fakeRepositories';
import { apiError, json, type MockApi, noContent } from './mockApi';

/** The API tokens of the user in memory: T1 to T3. */
export class FakeTokens {
    tokens: ApiToken[] = [];
    /** The secret of each issued token, by token ID. */
    readonly secrets = new Map<string, string>();
    /** T2 answers only after this settles. */
    responseGate: Promise<void> = Promise.resolve();

    constructor(api: MockApi, repositories: FakeRepositories) {
        api.on('GET', '/api/v1/tokens', () => json('lists.schema.json#/$defs/TokenList', { items: this.tokens }));
        api.on('POST', '/api/v1/tokens', async ({ body }) => {
            await this.responseGate;
            const { name, repositoryId = null, expiresAt = null } = body as CreateTokenInput;
            const repository = repositories.repositories.find(({ id }) => id === repositoryId);
            if (null !== repositoryId && undefined === repository) {
                return apiError(404, 'not_found', `No repository ${repositoryId} exists.`);
            }
            const secret = tokenSecret();
            const scope = undefined === repository ? { scope: 'all_repositories', repositoryId: null, repositoryName: null } : { scope: 'repository', repositoryId: repository.id, repositoryName: repository.name };
            const issued = token({ name, prefix: secret.slice(0, 9), expiresAt, ...scope } as Partial<ApiToken>);
            this.tokens = [issued, ...this.tokens];
            this.secrets.set(issued.id, secret);

            return json('api-token.schema.json#/$defs/ApiTokenWithSecret', { ...issued, secret }, 201);
        });
        api.on('DELETE', '/api/v1/tokens/:tokenId', ({ params }) => {
            this.tokens = this.tokens.map((known) => (known.id === params.tokenId ? { ...known, state: 'revoked', revokedAt: now() } : known));

            return noContent();
        });
    }
}
