import styles from './BlameLegend.module.css';

const SCALES = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9];

/** GitHub's age legend of the blame colours. */
export function BlameLegend() {
    return (
        <div className={styles.legend} data-testid="blame-legend">
            Older
            <span className={styles.swatches} aria-hidden="true">
                {SCALES.map((scale) => (
                    <span key={scale} className={styles.swatch} style={{ backgroundColor: `var(--display-orange-scale-${scale})` }} />
                ))}
            </span>
            Newer
        </div>
    );
}
