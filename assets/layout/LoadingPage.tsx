import { Spinner } from '@primer/react';
import styles from './StatusPage.module.css';

export function LoadingPage() {
    return (
        <div className={`${styles.page} ${styles.centered}`}>
            <Spinner size="large" srText="Loading" />
        </div>
    );
}
