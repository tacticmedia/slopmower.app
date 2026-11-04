import { type ComponentProps, useEffect, useState } from 'react';
import { gitHubImage } from '../code/githubCode';

const WITH_SCHEME = /^([a-z][a-z0-9+.-]*:|\/\/)/i;

type Props = ComponentProps<'img'> & { node?: unknown; repositoryId: string; commitSha: string };

/**
 * A README image of Browser only (D114). A repository path, as `readmeUrl()` resolves it, loads from GitHub into a
 * `blob:` URL. A URL with a scheme stays.
 */
export function GitHubImage({ src, node: _node, repositoryId, commitSha, ...props }: Props) {
    const path = 'string' === typeof src && !WITH_SCHEME.test(src) ? src : null;
    const [loaded, setLoaded] = useState<{ path: string; url: string } | null>(null);

    useEffect(() => {
        if (null === path) {
            return;
        }
        const controller = new AbortController();
        let url: string | null = null;
        gitHubImage(repositoryId, path, commitSha, controller.signal).then(
            (blob) => {
                if (controller.signal.aborted) {
                    return;
                }
                url = URL.createObjectURL(blob);
                setLoaded({ path, url });
            },
            () => {},
        );

        return () => {
            controller.abort();
            if (null !== url) {
                URL.revokeObjectURL(url);
            }
        };
    }, [repositoryId, commitSha, path]);

    if (null === path) {
        return <img src={src} {...props} />;
    }

    return loaded?.path === path ? <img src={loaded.url} {...props} /> : null;
}
