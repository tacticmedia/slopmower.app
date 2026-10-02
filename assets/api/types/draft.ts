import type { Anchor } from './anchor';
import type { DateTime, Markdown, Ulid } from './common';

export const DRAFT_KINDS = ['new_request', 'reply'] as const;
export type DraftKind = (typeof DRAFT_KINDS)[number];

type KindFields = { kind: 'new_request'; anchor: Anchor; requestId: null } | { kind: 'reply'; anchor: null; requestId: Ulid };

export type Draft = {
    id: Ulid;
    reviewId: Ulid;
    body: Markdown;
    createdAt: DateTime;
    updatedAt: DateTime;
} & KindFields;
