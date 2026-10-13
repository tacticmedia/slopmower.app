import { Heading, Link } from '@primer/react';
import type { TenantPlan } from '../api/types/me';
import styles from './Settings.module.css';

/** GitHub sends the trial end and the change date as UTC midnight. */
const dayFormat = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeZone: 'UTC' });

function Day({ value }: { value: string }) {
    return <time dateTime={value}>{dayFormat.format(new Date(value))}</time>;
}

export function PlanSummary({ plan }: { plan: TenantPlan }) {
    return (
        <section className={styles.pageSection} aria-labelledby="plan-title">
            <Heading as="h2" id="plan-title" className={styles.pageSectionTitle}>
                Plan
            </Heading>
            <p className={styles.note} data-testid="plan">
                Your plan is <strong>{plan.name}</strong>.
                {null !== plan.trialEndsAt && (
                    <>
                        {' '}
                        The free trial ends on <Day value={plan.trialEndsAt} />.
                    </>
                )}
                {null !== plan.pendingChange && (
                    <>
                        {' '}
                        It changes to <strong>{plan.pendingChange.name}</strong> on <Day value={plan.pendingChange.effectiveAt} />.
                    </>
                )}{' '}
                It is the highest plan of your GitHub account and of the accounts where the Slopmower GitHub App is installed.
            </p>
            {null !== plan.listingUrl && (
                <p className={styles.note}>
                    <Link href={plan.listingUrl}>Change the plan on GitHub Marketplace</Link>
                </p>
            )}
        </section>
    );
}
