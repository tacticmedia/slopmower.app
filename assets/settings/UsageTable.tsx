import { type Column, DataTable } from '@primer/react/experimental';
import type { KnownUsageLimit } from '../api/types/common';
import type { LimitUsage } from '../api/types/me';
import { formatSize } from '../layout/byteSize';

type Props = {
    usage: LimitUsage[];
    labelledBy: string;
    describedBy: string;
};

type Row = LimitUsage & { id: string };

const ITEM_NAMES: Record<KnownUsageLimit, string> = {
    repositories: 'Connected repositories',
    open_reviews: 'Open reviews',
    requests_per_review: 'Requests per review',
    repository_size: 'Size of one repository',
    tenant_storage: 'Size of all repositories',
};

const SIZE_LIMITS: ReadonlySet<string> = new Set<KnownUsageLimit>(['repository_size', 'tenant_storage']);

const count = new Intl.NumberFormat('en-US');

function formatAmount(limit: string, amount: number): string {
    return SIZE_LIMITS.has(limit) ? formatSize(amount) : count.format(amount);
}

export function UsageTable({ usage, labelledBy, describedBy }: Props) {
    const columns: Column<Row>[] = [
        { header: 'Item', id: 'limit', rowHeader: true, renderCell: ({ limit }) => ITEM_NAMES[limit as KnownUsageLimit] ?? limit },
        { header: 'Use', id: 'current', renderCell: ({ limit, current }) => formatAmount(limit, current) },
        { header: 'Limit', id: 'max', renderCell: ({ limit, max }) => (null === max ? 'No limit' : formatAmount(limit, max)) },
    ];

    return <DataTable aria-labelledby={labelledBy} aria-describedby={describedBy} data={usage.map((item) => ({ ...item, id: item.limit }))} columns={columns} />;
}
