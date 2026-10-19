import { Checkbox, FormControl, Label, Link, RelativeTime } from '@primer/react';
import { type Column, DataTable, Table } from '@primer/react/experimental';
import { Link as RouterLink } from 'react-router';
import type { Review } from '../../api/types/review';
import tableStyles from '../../layout/ScrollingTable.module.css';
import { shortSha } from '../../review/shortSha';

const COLUMNS: Column<Review>[] = [
    {
        id: 'title',
        header: 'Title',
        rowHeader: true,
        renderCell: (review) => (
            <Link as={RouterLink} to={`/reviews/${review.id}`}>
                {review.title}
            </Link>
        ),
    },
    { id: 'ref', header: 'Ref', renderCell: (review) => <code>{review.ref}</code> },
    { id: 'commit', header: 'Commit', width: 'auto', renderCell: (review) => <code title={review.commitSha}>{shortSha(review.commitSha)}</code> },
    {
        id: 'state',
        header: 'State',
        width: 'auto',
        renderCell: (review) => ('open' === review.state ? <Label variant="success">Open</Label> : <Label variant="done">Closed</Label>),
    },
    { id: 'requests', header: 'Requests', width: 'auto', align: 'end', renderCell: (review) => review.counts.total },
    {
        id: 'started',
        header: 'Started',
        renderCell: (review) => (
            <span>
                <RelativeTime datetime={review.createdAt} /> by {review.startedBy.name}
            </span>
        ),
    },
];

type ReviewListProps = {
    reviews: Review[];
    showClosed: boolean;
    onShowClosedChange: (showClosed: boolean) => void;
};

export function ReviewList({ reviews, showClosed, onShowClosedChange }: ReviewListProps) {
    return (
        <Table.Container data-testid="review-list" className={tableStyles.container}>
            <Table.Title as="h2" id="reviews">
                Reviews
            </Table.Title>
            <Table.Actions>
                <FormControl>
                    <Checkbox checked={showClosed} onChange={(event) => onShowClosedChange(event.target.checked)} />
                    <FormControl.Label>Show closed</FormControl.Label>
                </FormControl>
            </Table.Actions>
            {0 === reviews.length ? (
                <Table.Subtitle as="p" id="reviews-empty">
                    {showClosed ? 'This repository has no reviews.' : 'This repository has no open reviews.'}
                </Table.Subtitle>
            ) : (
                <DataTable aria-labelledby="reviews" data={reviews} columns={COLUMNS} />
            )}
        </Table.Container>
    );
}
