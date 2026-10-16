import type { Anchor } from './anchor';
import type { DateTime, Markdown, Ulid } from './common';

export const DRAFT_KINDS = ['new_request', 'reply'] as const;
export type DraftKind = (typeof DRAFT_KINDS)[number];

type KindFields =
    | { kind: 'new_request'; anchor: Anchor | null; requestId: null; title: string | null }
    | { kind: 'reply'; anchor: null; requestId: Ulid; title: null };

export type Draft = {
    id: Ulid;
    reviewId: Ulid;
    body: Markdown;
    /** Always false for a reply. */
    requireApproval: boolean;
    createdAt: DateTime;
    updatedAt: DateTime;
} & KindFields;
