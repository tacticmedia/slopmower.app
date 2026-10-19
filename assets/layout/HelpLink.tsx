import { Link } from '@primer/react';
import type { ReactNode } from 'react';
import styles from './HelpLink.module.css';
import type { HelpUrl } from './helpLinks';

/** A new tab, so the current page and a comment in progress stay. */
export function HelpLink({ href, children = 'Learn more', label }: { href: HelpUrl; children?: ReactNode; label?: string }) {
    return (
        <Link href={href} target="_blank" rel="noreferrer" aria-label={label} className={styles.link}>
            {children}
        </Link>
    );
}
