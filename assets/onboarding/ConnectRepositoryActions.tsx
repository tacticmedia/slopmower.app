import { LinkExternalIcon } from '@primer/octicons-react';
import { Banner, Button } from '@primer/react';
import type { ReactNode } from 'react';
import type { CandidateList } from '../api/types/lists';
import type { Me } from '../api/types/me';
import type { RepositoryCandidate } from '../api/types/repository-candidate';
import { CandidateConnection } from '../routes/repositories/CandidateConnection';
import styles from './ConnectRepositoryActions.module.css';

type Props = {
    features: Me['features'];
    candidates: CandidateList;
};

/** Step 1 of the onboarding: the repository sources of the instance. */
export function ConnectRepositoryActions({ features, candidates }: Props) {
    const github = candidates.items.filter(({ source }) => 'github' === source);
    const local = candidates.items.filter(({ source }) => 'local' === source);

    if (null === features.githubApp && !features.localRepositories) {
        return (
            <Banner variant="info" data-testid="no-repository-source">
                <Banner.Title as="h4">This instance has no repository source</Banner.Title>
                <Banner.Description>
                    The operator sets up a GitHub App or <code>APP_LOCAL_REPOSITORIES_DIR</code>.
                </Banner.Description>
            </Banner>
        );
    }

    return (
        <div className={styles.sources}>
            {null !== features.githubApp &&
                (0 === github.length ? (
                    <div className={styles.install} data-testid="onboarding-github">
                        <Button as="a" href={features.githubApp.installUrl} variant="primary" trailingVisual={LinkExternalIcon}>
                            Install the GitHub App
                        </Button>
                        <p className={styles.note}>Choose the account and the repositories that Slopmower may read. GitHub sends you back here.</p>
                    </div>
                ) : (
                    <CandidateGroup title="GitHub repositories" candidates={github} testId="onboarding-github">
                        {null !== candidates.githubConfigureUrl && (
                            <Button as="a" href={candidates.githubConfigureUrl} size="small" trailingVisual={LinkExternalIcon}>
                                Add repositories on GitHub
                            </Button>
                        )}
                    </CandidateGroup>
                ))}
            {features.localRepositories && (
                <CandidateGroup title="Local directories" candidates={local} testId="onboarding-local">
                    {0 === local.length && <p className={styles.note}>The local repositories directory contains no git repository.</p>}
                </CandidateGroup>
            )}
        </div>
    );
}

type CandidateGroupProps = {
    title: string;
    candidates: RepositoryCandidate[];
    testId: string;
    /** Below the list. */
    children?: ReactNode;
};

function CandidateGroup({ title, candidates, testId, children }: CandidateGroupProps) {
    return (
        <section className={styles.group} aria-label={title} data-testid={testId}>
            <h4 className={styles.groupTitle}>{title}</h4>
            {0 < candidates.length && (
                <ul className={styles.list}>
                    {candidates.map((candidate) => (
                        <li key={candidate.name} className={styles.row}>
                            <div className={styles.candidate}>
                                <span className={styles.name}>{candidate.name}</span>
                                <span className={styles.note}>{candidateDetails(candidate)}</span>
                            </div>
                            <CandidateConnection candidate={candidate} openRepository />
                        </li>
                    ))}
                </ul>
            )}
            {children}
        </section>
    );
}

function candidateDetails(candidate: RepositoryCandidate): string {
    if ('github' === candidate.source) {
        return `${candidate.github.private ? 'Private' : 'Public'}, installed on ${candidate.github.accountLogin}`;
    }

    return `HEAD branch ${candidate.local.headBranch ?? 'detached'}${candidate.local.bare ? ', bare' : ''}`;
}
