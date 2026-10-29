import type { Ulid } from '../api/types/common';
import type { CommentInput, CreateDraftInput, UpdateDraftInput } from '../api/types/inputs';

/**
 * The JSON body that comment components submit to the action of their route: `reply` for Q4, and the draft writes D2
 * to D4. A new request is always a draft (D84).
 */
export type CommentIntent =
    | { intent: 'reply'; requestId: Ulid; input: CommentInput }
    | { intent: 'draft'; input: CreateDraftInput }
    | { intent: 'editDraft'; draftId: Ulid; input: UpdateDraftInput }
    | { intent: 'deleteDraft'; draftId: Ulid };
