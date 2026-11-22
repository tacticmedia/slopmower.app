import { Button, Checkbox, CheckboxGroup, FormControl, Heading, Link, TextInput } from '@primer/react';
import { InlineMessage } from '@primer/react/experimental';
import { useState } from 'react';
import { Link as RouterLink, useFetcher, useLoaderData } from 'react-router';
import type { ActionResult } from '../../api/actionResult';
import { ActionErrorMessage } from '../../layout/ActionErrorMessage';
import { useDocumentTitle } from '../../layout/useDocumentTitle';
import { useSubmitOnce } from '../../layout/useSubmitOnce';
import { ApprovalModeField } from './ApprovalModeField';
import styles from './Issues.module.css';
import type { ProjectSettingsData } from './issueData';

/**
 * The name, the approval mode, and the repositories of a project: one form, one P3. A repository belongs to at most one
 * project, so a repository of another project is disabled and names that project.
 */
export function ProjectSettingsRoute() {
    const { project, projects, repositories } = useLoaderData<ProjectSettingsData>();
    const fetcher = useFetcher<ActionResult>();
    const submitOnce = useSubmitOnce(fetcher.submit);
    const busy = 'idle' !== fetcher.state;
    // "Saved." goes at the next change of the form.
    const [changed, setChanged] = useState(false);
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
                <ApprovalModeField value={project.approvalMode} />
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
