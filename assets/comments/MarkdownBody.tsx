import '@primer/css/dist/markdown.css';
import Markdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import styles from './MarkdownBody.module.css';

const REMARK_PLUGINS = [remarkGfm];

/**
 * react-markdown turns raw HTML into text and drops unsafe URLs, so a comment cannot inject markup.
 */
export function MarkdownBody({ markdown }: { markdown: string }) {
    return (
        <div className={`markdown-body ${styles.body}`} data-testid="markdown-body">
            <Markdown remarkPlugins={REMARK_PLUGINS}>{markdown}</Markdown>
        </div>
    );
}
