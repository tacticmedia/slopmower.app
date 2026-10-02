import { Heading, Link } from '@primer/react';
import { CopyButton } from '../layout/CopyButton';
import styles from './Settings.module.css';
import { skillInstallCommand } from './skillInstallCommand';

export function SkillInstall({ skillUrl }: { skillUrl: string }) {
    const command = skillInstallCommand(skillUrl);

    return (
        <section className={styles.pageSection} aria-labelledby="skill-install-title">
            <div className={styles.sectionHeader}>
                <Heading as="h2" id="skill-install-title" className={styles.pageSectionTitle}>
                    Agent skill
                </Heading>
                <CopyButton text={command} label="Copy command" />
            </div>
            <p className={styles.note}>
                Run this command in the root of your repository to install the Holistic Review skill.{' '}
                <Link href="/docs/connect-your-agent">Connect your agent</Link> shows every step.
            </p>
            <pre className={styles.code} data-testid="skill-install">
                <code>{command}</code>
            </pre>
        </section>
    );
}
