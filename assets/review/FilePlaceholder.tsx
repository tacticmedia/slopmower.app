import type { FileContent } from '../api/types/file-content';
import { formatSize, MAX_RENDERED_BYTES } from '../layout/byteSize';
import styles from './FilePlaceholder.module.css';

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

    return `File not shown: ${formatSize(sizeBytes)} is above the ${formatSize(MAX_RENDERED_BYTES)} limit.`;
}
