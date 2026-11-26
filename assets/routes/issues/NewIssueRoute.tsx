import { Button, FormControl, Heading, Link, Radio, RadioGroup, Select, TextInput } from '@primer/react';
import { useId, useRef, useState } from 'react';
import { Link as RouterLink, useFetcher, useLoaderData } from 'react-router';
import type { ActionResult } from '../../api/actionResult';
import { ISSUE_CATEGORIES, ISSUE_PRIORITIES, type IssueCategory, type IssuePriority } from '../../api/types/common';
import type { Project } from '../../api/types/project';
import { TITLE_MAX_CHARACTERS } from '../../comments/CommentBox';
import { MarkdownEditor } from '../../comments/MarkdownEditor';
import { useUnsavedComment } from '../../comments/UnsavedCommentGuard';
import { ActionErrorMessage } from '../../layout/ActionErrorMessage';
import { useDocumentTitle } from '../../layout/useDocumentTitle';
import { useSessionDraft } from '../../layout/useSessionDraft';
import { useSubmitOnce } from '../../layout/useSubmitOnce';
import { sectionContent, sectionTitle } from './IssueSection';
import { ISSUE_CATEGORY_TEXT } from './IssueStatusLabel';
import styles from './Issues.module.css';
import type { NewIssueIntent } from './issueData';

const PRIORITY_TEXT: Record<IssuePriority, string> = { top: 'Top', urgent: 'Urgent', high: 'High', normal: 'Normal', low: 'Low', none: 'None' };

const CATEGORY_CAPTIONS: Record<IssueCategory, string> = {
    bug: 'Something that exists is wrong.',
    change: 'A feature, an alteration, a refactoring, or an upgrade.',
};

/**
 * I6 with a title, a category, a priority, a problem, and acceptance criteria. No category is chosen at first (D133), and
 * the priority is "None", the last place (D137). The issue is a draft, and its page sets the status. The problem and the
 * acceptance criteria keep their text over a reload (D109).
 */
export function NewIssueRoute() {
    const project = useLoaderData<Project>();
    const fetcher = useFetcher<ActionResult>();
    const draftKey = `project:${project.key}:new-issue`;
    const [title, setTitle] = useState('');
    const [category, setCategory] = useState<IssueCategory | null>(null);
    const [priority, setPriority] = useState<IssuePriority>('none');
    const criteriaDraftKey = `${draftKey}:acceptance-criteria`;
    const [problem, setProblem] = useSessionDraft(draftKey);
    const [acceptanceCriteria, setAcceptanceCriteria] = useSessionDraft(criteriaDraftKey);
    const [preview, setPreview] = useState(false);
    const [criteriaPreview, setCriteriaPreview] = useState(false);
    const textarea = useRef<HTMLTextAreaElement>(null);
    const criteriaTextarea = useRef<HTMLTextAreaElement>(null);
    const editorId = useId();
    const criteriaEditorId = useId();
    const busy = 'idle' !== fetcher.state;
    // "Create issue" is off without a category, which also stops a submit with Enter.
    const intent: NewIssueIntent | null =
        null === category
            ? null
            : {
                  input: { title: title.trim(), category, priority, ...('' === problem.trim() ? {} : { problem }), ...('' === acceptanceCriteria.trim() ? {} : { acceptanceCriteria }) },
                  draftKeys: [draftKey, criteriaDraftKey],
              };
    const submitOnce = useSubmitOnce(() => (null === intent ? Promise.resolve() : fetcher.submit(intent, { method: 'post', encType: 'application/json' })));
    useUnsavedComment(!busy && ('' !== title.trim() || '' !== problem.trim()), draftKey);
    useUnsavedComment(!busy && '' !== acceptanceCriteria.trim(), criteriaDraftKey);
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
                <RadioGroup name="category" required disabled={busy} onChange={(selected) => setCategory(ISSUE_CATEGORIES.find((known) => known === selected) ?? null)}>
                    <RadioGroup.Label>Category</RadioGroup.Label>
                    {ISSUE_CATEGORIES.map((known) => (
                        <FormControl key={known}>
                            <Radio value={known} />
                            <FormControl.Label>{ISSUE_CATEGORY_TEXT[known]}</FormControl.Label>
                            <FormControl.Caption>{CATEGORY_CAPTIONS[known]}</FormControl.Caption>
                        </FormControl>
                    ))}
                </RadioGroup>
                <FormControl disabled={busy}>
                    <FormControl.Label>Priority</FormControl.Label>
                    <Select value={priority} onChange={(event) => setPriority(ISSUE_PRIORITIES.find((known) => known === event.target.value) ?? 'none')}>
                        {ISSUE_PRIORITIES.map((known) => (
                            <Select.Option key={known} value={known}>
                                {PRIORITY_TEXT[known]}
                            </Select.Option>
                        ))}
                    </Select>
                    <FormControl.Caption>The place among the open issues of the category: Top is first, None is last. Urgent, High, Normal, and Low come after the first 10, 25, 50, and 75 percent.</FormControl.Caption>
                </FormControl>
                <FormControl id={editorId}>
                    <FormControl.Label>{sectionTitle('problem', category)}</FormControl.Label>
                    <MarkdownEditor
                        ref={textarea}
                        id={editorId}
                        value={problem}
                        onChange={setProblem}
                        preview={preview}
                        onPreviewChange={setPreview}
                        placeholder={sectionContent('problem', category)}
                        readOnly={busy}
                    />
                    <FormControl.Caption>{sectionContent('problem', category)} Optional. The issue starts as a draft, and its page sets the status. Backlog and To do need a problem.</FormControl.Caption>
                </FormControl>
                <FormControl id={criteriaEditorId}>
                    <FormControl.Label>{sectionTitle('acceptanceCriteria', category)}</FormControl.Label>
                    <MarkdownEditor
                        ref={criteriaTextarea}
                        id={criteriaEditorId}
                        value={acceptanceCriteria}
                        onChange={setAcceptanceCriteria}
                        preview={criteriaPreview}
                        onPreviewChange={setCriteriaPreview}
                        placeholder={sectionContent('acceptanceCriteria', category)}
                        readOnly={busy}
                    />
                    <FormControl.Caption>{sectionContent('acceptanceCriteria', category)}</FormControl.Caption>
                </FormControl>
                <div className={styles.actions}>
                    <Button type="submit" variant="primary" disabled={'' === title.trim() || null === category} loading={busy}>
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
