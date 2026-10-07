import { Avatar, Button, Header } from '@primer/react';
import { NavLink, Outlet, useFetcher, useLoaderData } from 'react-router';
import type { Me } from '../api/types/me';
import { useLiveRevalidation } from '../live/useLiveRevalidation';
import styles from './RootLayout.module.css';
import { useSubmitOnce } from './useSubmitOnce';

export function RootLayout() {
    const me = useLoaderData<Me>();
    const signOut = useFetcher();
    const submitOnce = useSubmitOnce(signOut.submit);
    const live = useLiveRevalidation();

    return (
        <div className={styles.page} data-live={live ? 'connected' : 'disconnected'}>
            <Header className={styles.header}>
                <Header.Item>
                    <Header.Link as={NavLink} to="/" className={styles.brand}>
                        Holistic Review
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
                <Header.Item className={styles.user}>
                    {null !== me.user.avatarUrl && <Avatar src={me.user.avatarUrl} alt="" size={20} />}
                    <span data-testid="current-user">{me.user.login}</span>
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
        </div>
    );
}
