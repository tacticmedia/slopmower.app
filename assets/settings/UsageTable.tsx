import { type Column, DataTable } from '@primer/react/experimental';
import type { KnownUsageLimit } from '../api/types/common';
import type { LimitUsage } from '../api/types/me';

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

const BYTE_UNITS = ['B', 'KiB', 'MiB', 'GiB', 'TiB'] as const;

const count = new Intl.NumberFormat('en-US');

function formatBytes(bytes: number): string {
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < BYTE_UNITS.length - 1) {
        value /= 1024;
        unit += 1;
    }

    return `${Number.isInteger(value) ? value : value.toFixed(1)} ${BYTE_UNITS[unit]}`;
}

function formatAmount(limit: string, amount: number): string {
    return SIZE_LIMITS.has(limit) ? formatBytes(amount) : count.format(amount);
}

export function UsageTable({ usage, labelledBy, describedBy }: Props) {
    const columns: Column<Row>[] = [
        { header: 'Item', id: 'limit', rowHeader: true, renderCell: ({ limit }) => ITEM_NAMES[limit as KnownUsageLimit] ?? limit },
        { header: 'Use', id: 'current', renderCell: ({ limit, current }) => formatAmount(limit, current) },
        { header: 'Limit', id: 'max', renderCell: ({ limit, max }) => (null === max ? 'No limit' : formatAmount(limit, max)) },
    ];

    return <DataTable aria-labelledby={labelledBy} aria-describedby={describedBy} data={usage.map((item) => ({ ...item, id: item.limit }))} columns={columns} />;
}
