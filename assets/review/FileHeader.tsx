import { CopyButton } from '../layout/CopyButton';
import styles from './FileHeader.module.css';

export function FileHeader({ path }: { path: string }) {
    return (
        <header className={styles.header} data-testid="file-header">
            <h2 className={styles.path}>{path}</h2>
            <CopyButton text={path} label="Copy path" />
        </header>
    );
}
