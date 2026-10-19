import { CheckIcon } from '@primer/octicons-react';
import { Link, VisuallyHidden } from '@primer/react';
import { type ReactNode, useId } from 'react';
import { Link as RouterLink } from 'react-router';
import type { ApiToken } from '../api/types/api-token';
import styles from './OnboardingSteps.module.css';

type StepState = 'done' | 'current' | 'upcoming';

type Step = {
    title: string;
    description: ReactNode;
    /** The ID of the step heading in docs/user/getting-started.md. */
    docsFragment: string;
    state: StepState;
};

type Props = {
    /** The full form shows while no repository is connected. The compact form sits next to "Start a review" of a repository. */
    form: 'full' | 'compact';
    /** T1. Step 4 is done while it lists an active token. */
    tokens: ApiToken[];
    /** The actions of the current step. */
    actions?: ReactNode;
};

/** The steps show only while a repository has no open review, so steps 2 and 3 are never done here. */
export function OnboardingSteps({ form, tokens, actions }: Props) {
    const headingId = useId();
    const repositoryConnected = 'compact' === form;
    const steps: Step[] = [
        {
            title: 'Connect a repository',
            description: 'Slopmower makes its own copy of the code and does not change your repository.',
            docsFragment: '1-connect-a-repository',
            state: repositoryConnected ? 'done' : 'current',
        },
        {
            title: 'Pick a point to review',
            description: 'Choose a branch, a tag, or one of the latest commits.',
            docsFragment: '2-pick-a-point-to-review',
            state: repositoryConnected ? 'current' : 'upcoming',
        },
        {
            title: 'Start a review',
            description: 'The review stays on one commit, so each comment points to the code that you read.',
            docsFragment: '3-start-a-review',
            state: 'upcoming',
        },
        {
            title: 'Connect your agent',
            description: (
                <>
                    Create a token in{' '}
                    <Link as={RouterLink} to="/settings">
                        Settings
                    </Link>
                    , then follow <Link href="/docs/connect-your-agent">the agent guide</Link>.
                </>
            ),
            docsFragment: '4-connect-your-agent',
            state: tokens.some(({ state }) => 'active' === state) ? 'done' : 'upcoming',
        },
    ];
    const currentStep = steps.findIndex(({ state }) => 'current' === state) + 1;

    return (
        <section className={styles[form]} aria-labelledby={headingId} data-testid="onboarding-steps">
            <h2 id={headingId} className={styles.heading}>
                Getting started, step {currentStep} of {steps.length}
            </h2>
            <ol className={styles.steps}>
                {steps.map((step, index) => (
                    <li
                        key={step.docsFragment}
                        className={styles.step}
                        data-testid="onboarding-step"
                        data-state={step.state}
                        aria-current={'current' === step.state ? 'step' : undefined}
                    >
                        <span className={styles.marker} aria-hidden="true">
                            {'done' === step.state ? <CheckIcon /> : index + 1}
                        </span>
                        <div className={styles.body}>
                            <h3 className={styles.title}>
                                {step.title}
                                {'done' === step.state && <VisuallyHidden> (done)</VisuallyHidden>}
                            </h3>
                            <p className={styles.description}>
                                {step.description}{' '}
                                <Link href={`/docs/getting-started#${step.docsFragment}`} aria-label={`Learn more: ${step.title}`} className={styles.learnMore}>
                                    Learn more
                                </Link>
                            </p>
                            {'current' === step.state && undefined !== actions && <div className={styles.actions}>{actions}</div>}
                        </div>
                    </li>
                ))}
            </ol>
        </section>
    );
}
