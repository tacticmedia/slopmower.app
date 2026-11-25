import { Button, Checkbox, CheckboxGroup, FormControl, Heading, Link, TextInput } from '@primer/react';
import { InlineMessage } from '@primer/react/experimental';
import { useId, useRef, useState } from 'react';
import { Link as RouterLink, useFetcher, useLoaderData } from 'react-router';
import type { ActionResult } from '../../api/actionResult';
import { MarkdownEditor } from '../../comments/MarkdownEditor';
import { ActionErrorMessage } from '../../layout/ActionErrorMessage';
import { useDocumentTitle } from '../../layout/useDocumentTitle';
import { useSubmitOnce } from '../../layout/useSubmitOnce';
import { ApprovalModeField } from './ApprovalModeField';
import styles from './Issues.module.css';
import type { ProjectSettingsData } from './issueData';

/**
 * The name, the approval mode, and the repositories of a project: one form, one P3. A repository belongs to at most one
 * project, so a repository of another project is disabled and names that project. The guidelines show for `by_issue`
 * only, and P3 keeps them while they are hidden (D129).
 */
export function ProjectSettingsRoute() {
    const { project, projects, repositories } = useLoaderData<ProjectSettingsData>();
    const fetcher = useFetcher<ActionResult>();
    const submitOnce = useSubmitOnce(fetcher.submit);
    const busy = 'idle' !== fetcher.state;
    // "Saved." goes at the next change of the form.
    const [changed, setChanged] = useState(false);
    const [mode, setMode] = useState(project.approvalMode);
    const [guidelines, setGuidelines] = useState(project.approvalGuidelines ?? '');
    const [preview, setPreview] = useState(false);
    const guidelinesField = useRef<HTMLTextAreaElement>(null);
    const guidelinesId = useId();
    useDocumentTitle(`Settings of ${project.name}`);

    return (
        <div className={styles.page}>
            <header className={styles.header}>
                <Link as={RouterLink} to={`/projects/${project.key}`} className={styles.back}>
                    Issues of {project.name}
                </Link>
                <Heading as="h1">Project settings</Heading>
            </header>
            <fetcher.Form
                method="post"
                onChange={() => setChanged(true)}
                onSubmit={(event) => {
                    setChanged(false);
                    submitOnce(event);
                }}
                className={styles.form}
                data-testid="project-settings"
            >
                <FormControl disabled>
                    <FormControl.Label>Key</FormControl.Label>
                    <TextInput value={project.key} monospace readOnly />
                    <FormControl.Caption>The key is fixed, because each issue key starts with it.</FormControl.Caption>
                </FormControl>
                <FormControl required>
                    <FormControl.Label>Name</FormControl.Label>
                    <TextInput name="name" defaultValue={project.name} maxLength={255} autoComplete="off" block />
                </FormControl>
                <ApprovalModeField value={project.approvalMode} onChange={setMode} />
                {'by_issue' === mode && (
                    <FormControl id={guidelinesId}>
                        <FormControl.Label>Guidelines for the agent</FormControl.Label>
                        <MarkdownEditor
                            ref={guidelinesField}
                            id={guidelinesId}
                            value={guidelines}
                            onChange={setGuidelines}
                            preview={preview}
                            onPreviewChange={setPreview}
                            placeholder="Which change needs your approval, and which one an agent does on its own"
                            readOnly={busy}
                        />
                        <input type="hidden" name="approvalGuidelines" value={guidelines} />
                        <FormControl.Caption>An agent reads them when it creates an issue, and chooses User approval where they do not cover it.</FormControl.Caption>
                    </FormControl>
                )}
                <CheckboxGroup>
                    <CheckboxGroup.Label>Repositories</CheckboxGroup.Label>
                    <CheckboxGroup.Caption>
                        An agent tracks its work on a repository in the project that owns it. A token of one repository no longer sees this project when its repository leaves.
                    </CheckboxGroup.Caption>
                    {repositories.map((repository) => {
                        const owner = projects.find((known) => known.repositories.includes(repository.name));
                        const other = undefined !== owner && owner.key !== project.key;

                        return (
                            <FormControl key={repository.id} disabled={other}>
                                <Checkbox name="repositoryIds" value={repository.id} defaultChecked={owner?.key === project.key} />
                                <FormControl.Label>{repository.name}</FormControl.Label>
                                {other && <FormControl.Caption>Belongs to the project {owner.name}.</FormControl.Caption>}
                            </FormControl>
                        );
                    })}
                </CheckboxGroup>
                {0 === repositories.length && (
                    <p className={styles.muted}>
                        No repository is connected.{' '}
                        <Link as={RouterLink} to="/repositories">
                            Connect one
                        </Link>
                    </p>
                )}
                <div className={styles.actions}>
                    <Button type="submit" variant="primary" loading={busy}>
                        Save
                    </Button>
                    <ActionErrorMessage result={fetcher.data} />
                    {!busy && !changed && null === fetcher.data && (
                        <InlineMessage variant="success" role="status" data-testid="settings-saved">
                            Saved.
                        </InlineMessage>
                    )}
                </div>
            </fetcher.Form>
        </div>
    );
}
