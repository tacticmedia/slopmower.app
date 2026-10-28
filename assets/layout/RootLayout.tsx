import { QuestionIcon } from '@primer/octicons-react';
import { ActionList, ActionMenu, Avatar, Button, Header, IconButton } from '@primer/react';
import { NavLink, Outlet, useFetcher, useLoaderData, useMatch } from 'react-router';
import type { Me } from '../api/types/me';
import { useLiveRevalidation } from '../live/useLiveRevalidation';
import { useTours } from '../tour/useTours';
import { HELP } from './helpLinks';
import styles from './RootLayout.module.css';
import { useSubmitOnce } from './useSubmitOnce';

export function RootLayout() {
    const me = useLoaderData<Me>();
    const signOut = useFetcher();
    const submitOnce = useSubmitOnce(signOut.submit);
    const live = useLiveRevalidation();
    const { release } = document.body.dataset;

    return (
        <div className={styles.page} data-live={live ? 'connected' : 'disconnected'}>
            <Header className={styles.header}>
                <Header.Item>
                    <Header.Link as={NavLink} to="/" className={styles.brand}>
                        Slopmower
                    </Header.Link>
                </Header.Item>
                <Header.Item full>
                    <nav aria-label="Main" className={styles.navigation}>
                        <Header.Link as={NavLink} to="/repositories">
                            Repositories
                        </Header.Link>
                        <Header.Link as={NavLink} to="/settings">
                            Settings
                        </Header.Link>
                    </nav>
                </Header.Item>
                <Header.Item>
                    <HelpMenu />
                </Header.Item>
                <Header.Item className={styles.user}>
                    {null !== me.user.avatarUrl && <Avatar src={me.user.avatarUrl} alt="" size={20} />}
                    <span data-testid="current-user" className={styles.login}>
                        {me.user.login}
                    </span>
                    <signOut.Form method="post" action="/sign-out" onSubmit={submitOnce}>
                        <Button type="submit" size="small" loading={'idle' !== signOut.state}>
                            Sign out
                        </Button>
                    </signOut.Form>
                </Header.Item>
            </Header>
            <main className={styles.main}>
                <Outlet />
            </main>
            {release && <footer className={styles.footer}>Release {release}</footer>}
        </div>
    );
}

/** The help pages open in a new tab. On a review, "Take the review tour" sends A4 with no completed part. */
function HelpMenu() {
    const onReview = null !== useMatch('/reviews/:reviewId/*');
    const { save } = useTours();

    return (
        <ActionMenu>
            <ActionMenu.Anchor>
                <IconButton icon={QuestionIcon} aria-label="Help" variant="invisible" size="small" className={styles.help} />
            </ActionMenu.Anchor>
            <ActionMenu.Overlay align="end">
                <ActionList>
                    <ActionList.LinkItem href={HELP.center} target="_blank" rel="noreferrer">
                        Help center
                    </ActionList.LinkItem>
                    <ActionList.LinkItem href={HELP.keyboardShortcuts} target="_blank" rel="noreferrer">
                        Keyboard shortcuts
                    </ActionList.LinkItem>
                    {onReview && <ActionList.Item onSelect={() => save([])}>Take the review tour</ActionList.Item>}
                    <ActionList.Divider />
                    <ActionList.LinkItem href={HELP.connectYourAgent} target="_blank" rel="noreferrer">
                        Connect your agent
                    </ActionList.LinkItem>
                    <ActionList.LinkItem href={HELP.support} target="_blank" rel="noreferrer">
                        Support
                    </ActionList.LinkItem>
                </ActionList>
            </ActionMenu.Overlay>
        </ActionMenu>
    );
}
