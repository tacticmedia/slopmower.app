import { Heading, ToggleSwitch } from '@primer/react';
import { TOURS } from '../api/types/common';
import { ActionErrorMessage } from '../layout/ActionErrorMessage';
import { useTours } from '../tour/useTours';
import styles from './Settings.module.css';

const LABEL_ID = 'review-tour-switch';

/**
 * A4 through the root `preferences` action. On while a part of the tour is not completed. While the write runs, the
 * switch shows the requested value.
 */
export function ReviewTourSetting() {
    const { completed, saving, result, save } = useTours();
    const on = TOURS.some((tour) => !completed.includes(tour));

    return (
        <section className={styles.pageSection} aria-labelledby="review-tour-title">
            <Heading as="h2" id="review-tour-title" className={styles.pageSectionTitle}>
                Review tour
            </Heading>
            <p className={styles.note}>The tour shows the main actions of a review, one short part at a time. Your account remembers the parts that you finished or skipped.</p>
            <div className={styles.setting}>
                <span id={LABEL_ID}>Show the review tour</span>
                <ToggleSwitch
                    aria-labelledby={LABEL_ID}
                    size="small"
                    checked={on}
                    loading={saving}
                    onChange={(checked) => {
                        if (!saving) {
                            save(checked ? [] : TOURS);
                        }
                    }}
                />
            </div>
            <ActionErrorMessage result={result} />
        </section>
    );
}
