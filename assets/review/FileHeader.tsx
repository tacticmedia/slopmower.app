import type { ReactNode } from 'react';
import { CopyButton } from '../layout/CopyButton';
import styles from './FileHeader.module.css';

/**
 * `children` are the file commands, before the copy button.
 */
export function FileHeader({ path, children }: { path: string; children?: ReactNode }) {
    return (
        <header className={styles.header} data-testid="file-header">
            <h2 className={styles.path}>{path}</h2>
            <div className={styles.actions}>
                {children}
                <CopyButton text={path} label="Copy path" />
            </div>
        </header>
    );
}
