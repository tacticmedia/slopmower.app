import type { Ulid } from '../api/types/common';
import type { CommentInput, OpenRequestInput } from '../api/types/inputs';

/**
 * The JSON body that comment components submit to the action of their route: `open` for Q2, `reply` for Q4.
 */
export type CommentIntent = { intent: 'open'; input: OpenRequestInput } | { intent: 'reply'; requestId: Ulid; input: CommentInput };
