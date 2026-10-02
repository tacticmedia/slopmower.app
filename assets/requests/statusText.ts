import type { RequestStatus } from '../api/types/common';

export const STATUS_TEXT: Record<RequestStatus, string> = {
    open: 'Open',
    in_progress: 'In progress',
    ready_for_review: 'Ready for review',
    verified: 'Verified',
    reopened: 'Reopened',
    wont_do: "Won't do",
};
