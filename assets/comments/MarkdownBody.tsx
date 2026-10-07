import '@primer/css/dist/markdown.css';
import { type ComponentProps, useMemo } from 'react';
import Markdown, { type Components, defaultUrlTransform, type UrlTransform } from 'react-markdown';
import { Link as RouterLink } from 'react-router';
import remarkGfm from 'remark-gfm';
import styles from './MarkdownBody.module.css';

const REMARK_PLUGINS = [remarkGfm];

type Props = {
    markdown: string;
    /** Rewrites the safe URLs of links (`href`) and images (`src`). A shell path then opens without a page load. */
    resolveUrl?: (url: string, attribute: 'href' | 'src') => string;
    /** README text size instead of the comment size. */
    readme?: boolean;
};

/**
 * react-markdown turns raw HTML into text and drops unsafe URLs, so a comment cannot inject markup.
 */
export function MarkdownBody({ markdown, resolveUrl, readme = false }: Props) {
    const urlTransform = useMemo<UrlTransform | undefined>(() => {
        if (undefined === resolveUrl) {
            return undefined;
        }

        return (url, key) => {
            const safe = defaultUrlTransform(url);

            return '' === safe ? safe : resolveUrl(safe, 'src' === key ? 'src' : 'href');
        };
    }, [resolveUrl]);
    const components = useMemo<Components | undefined>(() => (undefined === resolveUrl ? undefined : { a: ShellLink }), [resolveUrl]);

    return (
        <div className={`markdown-body ${readme ? styles.readme : styles.body}`} data-testid="markdown-body">
            <Markdown remarkPlugins={REMARK_PLUGINS} urlTransform={urlTransform} components={components}>
                {markdown}
            </Markdown>
        </div>
    );
}

function ShellLink({ href, node: _node, ...props }: ComponentProps<'a'> & { node?: unknown }) {
    return undefined !== href && href.startsWith('/reviews/') ? <RouterLink to={href} {...props} /> : <a href={href} {...props} />;
}
