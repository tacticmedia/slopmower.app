import type { Ulid } from './common';

export type UserActor = {
    kind: 'user';
    userId: Ulid;
    agentName: null;
    name: string;
    avatarUrl: string | null;
};

export type AgentActor = {
    kind: 'agent';
    /** The user the agent acts for. */
    userId: Ulid;
    /** The API token name. */
    agentName: string;
    name: string;
    avatarUrl: null;
};

export type SystemActor = {
    kind: 'system';
    userId: null;
    agentName: null;
    name: string;
    avatarUrl: null;
};

export type Actor = UserActor | AgentActor | SystemActor;
