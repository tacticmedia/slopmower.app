import { CopyButton } from '../layout/CopyButton';
import styles from './CopyField.module.css';

export function ReviewIdCopy({ reviewId }: { reviewId: string }) {
    return (
        <div className={styles.field}>
            <span className={styles.label}>Review ID</span>
            <code className={styles.value} data-testid="review-id">
                {reviewId}
            </code>
            <CopyButton text={reviewId} label="Copy ID" />
        </div>
    );
}
