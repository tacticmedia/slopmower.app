import { Link } from '@primer/react';
import { Link as RouterLink, useRouteLoaderData } from 'react-router';
import type { Me } from '../api/types/me';
import { splitIssueKeys } from './issueKeys';

/** Plain text in which each issue key is a link to its issue (D125). Not for a text inside a link. */
export function IssueKeyText({ text }: { text: string }) {
    const projectKeys = useRouteLoaderData<Me>('root')?.projectKeys ?? [];

    return splitIssueKeys(text, projectKeys).map(({ text: part, issueHref }, index) =>
        null === issueHref ? (
            part
        ) : (
            <Link key={index} as={RouterLink} to={issueHref} inline>
                {part}
            </Link>
        ),
    );
}
