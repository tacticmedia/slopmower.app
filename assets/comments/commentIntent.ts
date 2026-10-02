import type { Ulid } from '../api/types/common';
import type { CommentInput, CreateDraftInput, OpenRequestInput, UpdateDraftInput } from '../api/types/inputs';

/**
 * The JSON body that comment components submit to the action of their route: `open` for Q2, `reply` for Q4, and the
 * draft writes D2 to D4.
 */
export type CommentIntent =
    | { intent: 'open'; input: OpenRequestInput }
    | { intent: 'reply'; requestId: Ulid; input: CommentInput }
    | { intent: 'draft'; input: CreateDraftInput }
    | { intent: 'editDraft'; draftId: Ulid; input: UpdateDraftInput }
    | { intent: 'deleteDraft'; draftId: Ulid };
