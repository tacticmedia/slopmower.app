import type { ReactNode } from 'react';
import { CopyButton } from '../layout/CopyButton';
import { PlanToApproveLabel } from '../requests/PlanToApproveLabel';
import styles from './FileHeader.module.css';

type Props = {
    path: string;
    /** A thread of the file has a pending proposal. */
    planToApprove: boolean;
    /** The file commands, before the copy button. */
    children?: ReactNode;
};

export function FileHeader({ path, planToApprove, children }: Props) {
    return (
        <header className={styles.header} data-testid="file-header">
            <div className={styles.title}>
                <h2 className={styles.path}>{path}</h2>
                <PlanToApproveLabel pending={planToApprove} />
            </div>
            <div className={styles.actions}>
                {children}
                <CopyButton text={path} label="Copy path" />
            </div>
        </header>
    );
}
