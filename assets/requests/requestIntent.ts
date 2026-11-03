import type {
    AcceptSuggestionInput,
    ApproveProposalInput,
    DismissSuggestionInput,
    OptionalNoteInput,
    PlanRequirementInput,
    RejectProposalInput,
    RequiredNoteInput,
    TitleInput,
    WontDoInput,
} from '../api/types/inputs';
import type { CommentIntent } from '../comments/commentIntent';

/**
 * The JSON body of the user commands on the request detail. Each intent is named after its `allowedActions` value.
 * `next` opens the next request that waits for the user after the write.
 */
export type RequestCommandIntent =
    | { intent: 'verify'; input: OptionalNoteInput; next?: boolean }
    | { intent: 'reopen'; input: RequiredNoteInput }
    | { intent: 'mark_wont_do'; input: WontDoInput }
    | { intent: 'release'; input: OptionalNoteInput }
    | { intent: 'approve_proposal'; input: ApproveProposalInput; next?: boolean }
    | { intent: 'reject_proposal'; input: RejectProposalInput }
    | { intent: 'set_plan_requirement'; input: PlanRequirementInput }
    | { intent: 'accept_suggestion'; input: AcceptSuggestionInput; next?: boolean }
    | { intent: 'dismiss_suggestion'; input: DismissSuggestionInput }
    | { intent: 'set_title'; input: TitleInput };

/** A `ThreadBox` reply submits the `reply` intent of {@link CommentIntent}. */
export type RequestIntent = RequestCommandIntent | Extract<CommentIntent, { intent: 'reply' }>;
