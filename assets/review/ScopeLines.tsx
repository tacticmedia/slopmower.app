import type { Symbol as CodeSymbol } from '../api/types/symbol';
import styles from './ScopeLines.module.css';

type Props = {
    /** Outermost first. */
    scopes: readonly CodeSymbol[];
    lines: readonly string[];
    onChoose(line: number): void;
};

/**
 * GitHub's sticky lines: the declaration lines of the class and the function around the first line below the header.
 */
export function ScopeLines({ scopes, lines, onChoose }: Props) {
    if (0 === scopes.length) {
        return null;
    }

    return (
        <ol className={styles.scopes} aria-label="Enclosing scopes" data-testid="scope-lines">
            {scopes.map(({ fqn, declarationStartLine }) => (
                <li key={fqn}>
                    <button type="button" className={styles.scope} onClick={() => onChoose(declarationStartLine)}>
                        <span className={styles.number}>{declarationStartLine}</span>
                        <span className={styles.text}>{lines[declarationStartLine - 1] ?? ''}</span>
                    </button>
                </li>
            ))}
        </ol>
    );
}
