import type { FileContent } from '../api/types/file-content';
import styles from './FilePlaceholder.module.css';

const BYTES_PER_MIB = 1048576;

/**
 * Stands in for the code of a file that V7 marks as not renderable.
 */
export function FilePlaceholder({ content }: { content: FileContent }) {
    return (
        <p className={styles.placeholder} data-testid="file-placeholder">
            {reason(content)}
        </p>
    );
}

function reason({ type, symlinkTarget, binary, sizeBytes }: FileContent): string {
    if ('symlink' === type) {
        return null === symlinkTarget ? 'Symbolic link. Its target is not valid UTF-8.' : `Symbolic link to ${symlinkTarget}`;
    }

    if (binary) {
        return 'Binary file not shown.';
    }

    return `File not shown: ${(sizeBytes / BYTES_PER_MIB).toFixed(1)} MiB is above the 1 MiB limit.`;
}
