import { Button, FormControl, TextInput } from '@primer/react';
import { useFetcher } from 'react-router';
import type { ActionResult } from '../../api/actionResult';
import { ActionErrorMessage } from '../../layout/ActionErrorMessage';
import styles from './RepositoryRoute.module.css';

export function StartReviewForm({ defaultBranch }: { defaultBranch: string | null }) {
    const start = useFetcher<ActionResult>();

    return (
        <section aria-labelledby="start-review">
            <h2 id="start-review" className={styles.sectionTitle}>
                Start a review
            </h2>
            <start.Form method="post" className={styles.startForm} data-testid="start-review-form">
                <input type="hidden" name="intent" value="start" />
                <FormControl>
                    <FormControl.Label>Ref</FormControl.Label>
                    <TextInput name="ref" defaultValue={defaultBranch ?? ''} placeholder="Default branch" monospace block />
                    <FormControl.Caption>A branch, a tag, or a commit SHA.</FormControl.Caption>
                </FormControl>
                <FormControl>
                    <FormControl.Label>Title</FormControl.Label>
                    <TextInput name="title" placeholder="<ref> @ <commit>" block />
                    <FormControl.Caption>Optional.</FormControl.Caption>
                </FormControl>
                <div className={styles.actions}>
                    <Button type="submit" variant="primary" loading={'idle' !== start.state}>
                        Start review
                    </Button>
                    <ActionErrorMessage result={start.data} />
                </div>
            </start.Form>
        </section>
    );
}
