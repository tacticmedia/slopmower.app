import '@primer/css/dist/markdown.css';
import type { ElementContent, Root } from 'hast';
import { type ComponentProps, type ReactNode, useMemo } from 'react';
import Markdown, { type Components, defaultUrlTransform, type UrlTransform } from 'react-markdown';
import { Link as RouterLink, useRouteLoaderData } from 'react-router';
import remarkGfm from 'remark-gfm';
import { SKIP, visitParents } from 'unist-util-visit-parents';
import type { Me } from '../api/types/me';
import { splitIssueKeys } from './issueKeys';
import styles from './MarkdownBody.module.css';

const REMARK_PLUGINS = [remarkGfm];

const SHELL_PATH = /^\/(issues|reviews)\//;

type Props = {
    markdown: string;
    /** Rewrites the safe URLs of links (`href`) and images (`src`). A shell path then opens without a page load. */
    resolveUrl?: (url: string, attribute: 'href' | 'src') => string;
    /** README text size instead of the comment size. */
    readme?: boolean;
    /** Renders an image instead of `img`, with the `src` of `resolveUrl`. */
    image?: (props: ComponentProps<'img'>) => ReactNode;
};

/**
 * react-markdown turns raw HTML into text and drops unsafe URLs, so a comment cannot inject markup.
 */
export function MarkdownBody({ markdown, resolveUrl, readme = false, image }: Props) {
    const projectKeys = useRouteLoaderData<Me>('root')?.projectKeys;
    const rehypePlugins = useMemo(() => [issueKeyLinks(projectKeys ?? [])], [projectKeys]);
    const urlTransform = useMemo<UrlTransform | undefined>(() => {
        if (undefined === resolveUrl) {
            return undefined;
        }

        return (url, key, node) => {
            const safe = defaultUrlTransform(url);

            // The link of an issue key is a shell path already.
            return '' === safe || 'dataIssueKey' in node.properties ? safe : resolveUrl(safe, 'src' === key ? 'src' : 'href');
        };
    }, [resolveUrl]);
    const components = useMemo<Components>(() => ({ a: ShellLink, ...(undefined === image ? {} : { img: image }) }), [image]);

    return (
        <div className={`markdown-body ${readme ? styles.readme : styles.body}`} data-testid="markdown-body">
            <Markdown remarkPlugins={REMARK_PLUGINS} rehypePlugins={rehypePlugins} urlTransform={urlTransform} components={components}>
                {markdown}
            </Markdown>
        </div>
    );
}

/**
 * A rehype plugin: each issue key of a text is a link to its issue (D125), also in inline code. The text of a link and
 * of a code block stays.
 */
function issueKeyLinks(projectKeys: readonly string[]) {
    return () =>
        (tree: Root): void =>
            visitParents(tree, (node, ancestors, index) => {
                if ('element' === node.type && ('a' === node.tagName || 'pre' === node.tagName)) {
                    return SKIP;
                }
                const parent = ancestors.at(-1);
                if ('text' !== node.type || undefined === parent || undefined === index) {
                    return undefined;
                }
                const parts = splitIssueKeys(node.value, projectKeys).map(
                    ({ text, issueHref }): ElementContent =>
                        null === issueHref ? { type: 'text', value: text } : { type: 'element', tagName: 'a', properties: { href: issueHref, dataIssueKey: text }, children: [{ type: 'text', value: text }] },
                );
                parent.children.splice(index, 1, ...parts);

                return [SKIP, index + parts.length];
            });
}

function ShellLink({ href, node: _node, ...props }: ComponentProps<'a'> & { node?: unknown }) {
    return undefined !== href && SHELL_PATH.test(href) ? <RouterLink to={href} {...props} /> : <a href={href} {...props} />;
}
