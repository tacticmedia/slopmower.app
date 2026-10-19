import type { CommitSha, DateTime } from './common';

/** One commit of a commit list. */
export type Commit = {
    sha: CommitSha;
    /** The first paragraph of the message on one line. Empty for an empty message. */
    subject: string;
    /** As the commit records it, without a mailmap. */
    authorName: string;
    authoredAt: DateTime;
    committedAt: DateTime;
};
