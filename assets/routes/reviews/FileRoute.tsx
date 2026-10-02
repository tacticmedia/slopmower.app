import { Spinner } from '@primer/react';
import { lazy, Suspense, useCallback, useState } from 'react';
import { type LoaderFunctionArgs, useLoaderData, useLocation, useNavigate } from 'react-router';
import { apiGet, apiGetText } from '../../api/client';
import type { FileContent } from '../../api/types/file-content';
import { formatLineHash, type LineRange, parseLineHash } from '../../code/lineHash';
import { FileHeader } from '../../review/FileHeader';
import { FilePlaceholder } from '../../review/FilePlaceholder';
import { encodedRouteParam } from '../encodedRouteParam';
import styles from './FileRoute.module.css';

// A separate chunk, so the code viewer library stays out of the shell bundle.
const CodeFile = lazy(() => import('../../code/CodeFile').then(({ CodeFile }) => ({ default: CodeFile })));

/** `text` is null when the file is not renderable. */
export type FileView = { content: FileContent; text: string | null };

export async function fileLoader({ request, params }: LoaderFunctionArgs): Promise<FileView> {
    const path = encodeURIComponent(params['*'] ?? '');
    const content = await apiGet<FileContent>(`/api/v1/reviews/${encodedRouteParam(params, 'reviewId')}/file?path=${path}`, request.signal);

    return { content, text: content.renderable ? await apiGetText(content.rawUrl, request.signal) : null };
}

export function FileRoute() {
    const { content, text } = useLoaderData<FileView>();

    return (
        <section className={styles.file} aria-label={content.path}>
            <FileHeader path={content.path} />
            {null === text ? <FilePlaceholder content={content} /> : <Code content={content} text={text} />}
        </section>
    );
}

/**
 * The selection follows the line hash, and a finished selection writes it back. Not keyed by path, so the worker
 * pool in CodeFile stays up while files change.
 */
function Code({ content, text }: { content: FileContent; text: string }) {
    const { hash } = useLocation();
    const navigate = useNavigate();
    const [link, setLink] = useState({ path: content.path, hash });
    const [selection, setSelection] = useState(() => linkedRange(hash, content.lineCount));

    if (link.path !== content.path || link.hash !== hash) {
        setLink({ path: content.path, hash });
        setSelection(linkedRange(hash, content.lineCount));
    }

    const writeHash = useCallback(
        // Clearing the hash is not a hash-only change for the router, which would run every loader again.
        (range: LineRange | null) => void navigate({ hash: null === range ? '' : formatLineHash(range) }, { replace: true, defaultShouldRevalidate: false }),
        [navigate],
    );

    return (
        <div className={styles.viewport} data-testid="code-view">
            <Suspense
                fallback={
                    <div className={styles.loading}>
                        <Spinner srText="Loading the code" />
                    </div>
                }
            >
                <CodeFile
                    path={content.path}
                    blobSha={content.blobSha}
                    contents={text}
                    selection={selection}
                    onSelectionChange={setSelection}
                    onSelectionCommit={writeHash}
                    className={styles.scroller}
                />
            </Suspense>
        </div>
    );
}

function linkedRange(hash: string, lineCount: number | null): LineRange | null {
    const range = parseLineHash(hash);

    return null !== range && null !== lineCount && range.end <= lineCount ? range : null;
}
