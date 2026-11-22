import { Button, Heading, Link } from '@primer/react';
import { type Column, DataTable, Table } from '@primer/react/experimental';
import { useState } from 'react';
import { Link as RouterLink, useLoaderData } from 'react-router';
import type { Project } from '../../api/types/project';
import tableStyles from '../../layout/ScrollingTable.module.css';
import { useDocumentTitle } from '../../layout/useDocumentTitle';
import { APPROVAL_MODE_NAMES } from './ApprovalModeField';
import styles from './Issues.module.css';
import { NewProjectDialog } from './NewProjectDialog';

/** The key is the row ID of the table. */
type Row = Project & { id: string };

const COLUMNS: Column<Row>[] = [
    {
        id: 'key',
        header: 'Key',
        rowHeader: true,
        width: 'auto',
        renderCell: (project) => (
            <Link as={RouterLink} to={`/projects/${project.key}`}>
                {project.key}
            </Link>
        ),
    },
    { id: 'name', header: 'Name', renderCell: (project) => project.name },
    { id: 'approvalMode', header: 'Plan approval', renderCell: (project) => APPROVAL_MODE_NAMES[project.approvalMode] },
    { id: 'repositories', header: 'Repositories', renderCell: (project) => (0 === project.repositories.length ? '-' : project.repositories.join(', ')) },
];

export function ProjectsRoute() {
    const projects = useLoaderData<Project[]>();
    const [creating, setCreating] = useState(false);
    useDocumentTitle('Issues');

    return (
        <div className={styles.page}>
            <Heading as="h1">Issues</Heading>
            <Table.Container className={tableStyles.container} data-testid="projects">
                <Table.Title as="h2" id="projects">
                    Projects
                </Table.Title>
                <Table.Subtitle as="p" id="projects-subtitle">
                    A project holds the issues of its repositories. An agent tracks its work on a repository in the project that owns it.
                </Table.Subtitle>
                <Table.Actions>
                    <Button variant="primary" onClick={() => setCreating(true)}>
                        New project
                    </Button>
                </Table.Actions>
                {0 === projects.length ? (
                    <p className={styles.emptyTable}>No projects yet.</p>
                ) : (
                    <DataTable aria-labelledby="projects" aria-describedby="projects-subtitle" data={projects.map((project) => ({ ...project, id: project.key }))} columns={COLUMNS} />
                )}
            </Table.Container>
            {creating && <NewProjectDialog onClose={() => setCreating(false)} />}
        </div>
    );
}
