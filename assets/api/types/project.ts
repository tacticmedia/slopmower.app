import type { ApprovalMode, ProjectKey } from './common';

/** A project of the issue tracker (D116). A repository belongs to at most one project. */
export type Project = {
    key: ProjectKey;
    name: string;
    approvalMode: ApprovalMode;
    /** The names of the repositories of the project that the caller sees, in name order. */
    repositories: string[];
};
