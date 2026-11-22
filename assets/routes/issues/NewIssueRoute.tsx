import { Button, FormControl, Heading, Link, TextInput } from '@primer/react';
import { useId, useRef, useState } from 'react';
import { Link as RouterLink, useFetcher, useLoaderData } from 'react-router';
import type { ActionResult } from '../../api/actionResult';
import type { Project } from '../../api/types/project';
import { TITLE_MAX_CHARACTERS } from '../../comments/CommentBox';
import { MarkdownEditor } from '../../comments/MarkdownEditor';
import { useUnsavedComment } from '../../comments/UnsavedCommentGuard';
import { ActionErrorMessage } from '../../layout/ActionErrorMessage';
import { useDocumentTitle } from '../../layout/useDocumentTitle';
import { useSessionDraft } from '../../layout/useSessionDraft';
import { useSubmitOnce } from '../../layout/useSubmitOnce';
import { SECTION_CONTENT } from './IssueSection';
import styles from './Issues.module.css';
import type { NewIssueIntent } from './issueData';

/**
 * I6 with a title and a problem. The issue is a draft, and its page sets the status. The problem keeps its text over a
 * reload (D109).
 */
export function NewIssueRoute() {
    const project = useLoaderData<Project>();
    const fetcher = useFetcher<ActionResult>();
    const draftKey = `project:${project.key}:new-issue`;
    const [title, setTitle] = useState('');
    const [problem, setProblem] = useSessionDraft(draftKey);
    const [preview, setPreview] = useState(false);
    const textarea = useRef<HTMLTextAreaElement>(null);
    const editorId = useId();
    const busy = 'idle' !== fetcher.state;
    const intent: NewIssueIntent = { input: { title: title.trim(), ...('' === problem.trim() ? {} : { problem }) }, draftKey };
    const submitOnce = useSubmitOnce(() => fetcher.submit(intent, { method: 'post', encType: 'application/json' }));
    useUnsavedComment(!busy && ('' !== title.trim() || '' !== problem.trim()), draftKey);
    useDocumentTitle(`New issue of ${project.name}`);

    return (
        <div className={styles.page}>
            <header className={styles.header}>
                <Link as={RouterLink} to={`/projects/${project.key}`} className={styles.back}>
                    Issues of {project.name}
                </Link>
                <Heading as="h1">New issue</Heading>
            </header>
            <fetcher.Form method="post" onSubmit={submitOnce} className={styles.form} data-testid="new-issue">
                <FormControl required>
                    <FormControl.Label>Title</FormControl.Label>
                    <TextInput value={title} onChange={(event) => setTitle(event.target.value)} maxLength={TITLE_MAX_CHARACTERS} autoComplete="off" readOnly={busy} block autoFocus />
                </FormControl>
                <FormControl id={editorId}>
                    <FormControl.Label>Problem</FormControl.Label>
                    <MarkdownEditor ref={textarea} id={editorId} value={problem} onChange={setProblem} preview={preview} onPreviewChange={setPreview} placeholder={SECTION_CONTENT.problem} readOnly={busy} />
                    <FormControl.Caption>Optional. The issue starts as a draft, and its page sets the status. Backlog and To do need a problem.</FormControl.Caption>
                </FormControl>
                <div className={styles.actions}>
                    <Button type="submit" variant="primary" disabled={'' === title.trim()} loading={busy}>
                        Create issue
                    </Button>
                    <Button as={RouterLink} to={`/projects/${project.key}`}>
                        Cancel
                    </Button>
                    <ActionErrorMessage result={fetcher.data} />
                </div>
            </fetcher.Form>
        </div>
    );
}
