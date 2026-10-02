import { Button, Heading } from '@primer/react';
import { Table } from '@primer/react/experimental';
import { useState } from 'react';
import { useLoaderData, useRouteLoaderData } from 'react-router';
import type { Me } from '../../api/types/me';
import { CreateTokenDialog } from '../../settings/CreateTokenDialog';
import { DeleteAccountDialog } from '../../settings/DeleteAccountDialog';
import { RepositoryDataTable } from '../../settings/RepositoryDataTable';
import styles from '../../settings/Settings.module.css';
import { SkillInstall } from '../../settings/SkillInstall';
import { TokenTable } from '../../settings/TokenTable';
import type { SettingsData } from './settingsData';

export function SettingsRoute() {
    const { tokens, repositories } = useLoaderData<SettingsData>();
    const me = useRouteLoaderData<Me>('root');
    const [creating, setCreating] = useState(false);

    if (undefined === me) {
        throw new Error('The settings route renders inside the root route.');
    }

    return (
        <>
            <Heading as="h1">Settings</Heading>
            <Table.Container>
                <Table.Title as="h2" id="api-tokens">
                    API tokens
                </Table.Title>
                <Table.Subtitle as="p" id="api-tokens-subtitle">
                    Agents connect to the MCP server with a token. The token name is the agent name.
                </Table.Subtitle>
                <Table.Actions>
                    <Button variant="primary" onClick={() => setCreating(true)}>
                        Create token
                    </Button>
                </Table.Actions>
                <TokenTable tokens={tokens} labelledBy="api-tokens" describedBy="api-tokens-subtitle" />
            </Table.Container>
            <SkillInstall skillUrl={me.links.skillUrl} />
            <div className={styles.pageSection}>
                <Table.Container>
                    <Table.Title as="h2" id="repository-data">
                        Repository data
                    </Table.Title>
                    <Table.Subtitle as="p" id="repository-data-subtitle">
                        Deleting the data of a repository deletes its reviews, its symbol index, and its server clone.
                    </Table.Subtitle>
                    <RepositoryDataTable repositories={repositories} labelledBy="repository-data" describedBy="repository-data-subtitle" />
                </Table.Container>
            </div>
            <section className={styles.pageSection} aria-labelledby="delete-account-title">
                <Heading as="h2" id="delete-account-title" className={styles.pageSectionTitle}>
                    Delete account
                </Heading>
                <p className={styles.note}>Deletes your account and all its data. You cannot undo this.</p>
                <DeleteAccountDialog user={me.user} />
            </section>
            {creating && <CreateTokenDialog repositories={repositories} mcpUrl={me.links.mcpUrl} onClose={() => setCreating(false)} />}
        </>
    );
}
