import { describe, expect, it } from 'vitest';
import { API_TOKEN_SCOPES, API_TOKEN_STATES } from './types/api-token';
import { CARRY_OVER_OUTCOMES } from './types/carry-over-result';
import { COMMIT_DIFF_FILE_STATUSES } from './types/commit-diff';
import {
    ACTIONS,
    ACTOR_KINDS,
    ANCHOR_KINDS,
    AWAITING_VALUES,
    COMMIT_LINK_STATES,
    ERROR_CODES,
    EVENT_TYPES,
    INDEX_STATES,
    LOCATION_STATUSES,
    ORPHAN_REASONS,
    PROPOSAL_STATES,
    REPOSITORY_ACCESSES,
    REPOSITORY_SOURCES,
    REQUEST_STATUSES,
    RESOLUTIONS,
    REVIEW_STATES,
    SYMBOL_KINDS,
    THREAD_ENTRY_TYPES,
} from './types/common';
import { DRAFT_KINDS } from './types/draft';
import { REPOSITORY_UNAVAILABLE_REASONS } from './types/error';
import { FILE_CONTENT_TYPES } from './types/file-content';
import { LOCATION_TARGETS } from './types/location';
import { SIGN_IN_METHODS } from './types/me';
import { SYNC_STATES } from './types/repository';
import { TREE_ENTRY_TYPES } from './types/tree-entry';

type Json = null | boolean | number | string | Json[] | { [key: string]: Json };

const schemas = new Map(
    Object.entries(
        import.meta.glob<Json>('../../docs/specs/schemas/*.schema.json', { eager: true, import: 'default' }),
    ).map(([path, schema]) => [path.slice(path.lastIndexOf('/') + 1), schema]),
);

const ENUM_ARRAYS: [schemaFileName: string, jsonPointer: string, tsArray: readonly string[]][] = [
    ['common.schema.json', '/$defs/RepositorySource/enum', REPOSITORY_SOURCES],
    ['common.schema.json', '/$defs/RepositoryAccess/enum', REPOSITORY_ACCESSES],
    ['common.schema.json', '/$defs/ReviewState/enum', REVIEW_STATES],
    ['common.schema.json', '/$defs/IndexState/enum', INDEX_STATES],
    ['common.schema.json', '/$defs/RequestStatus/enum', REQUEST_STATUSES],
    ['common.schema.json', '/$defs/Awaiting/enum', AWAITING_VALUES],
    ['common.schema.json', '/$defs/Resolution/enum', RESOLUTIONS],
    ['common.schema.json', '/$defs/AnchorKind/enum', ANCHOR_KINDS],
    ['common.schema.json', '/$defs/ActorKind/enum', ACTOR_KINDS],
    ['common.schema.json', '/$defs/CommitLinkState/enum', COMMIT_LINK_STATES],
    ['common.schema.json', '/$defs/LocationStatus/enum', LOCATION_STATUSES],
    ['common.schema.json', '/$defs/OrphanReason/enum', ORPHAN_REASONS],
    ['common.schema.json', '/$defs/ProposalState/enum', PROPOSAL_STATES],
    ['common.schema.json', '/$defs/SymbolKind/anyOf/0/enum', SYMBOL_KINDS],
    ['common.schema.json', '/$defs/Action/anyOf/0/enum', ACTIONS],
    ['common.schema.json', '/$defs/EventType/anyOf/0/enum', EVENT_TYPES],
    ['common.schema.json', '/$defs/ThreadEntryType/anyOf/0/enum', THREAD_ENTRY_TYPES],
    ['common.schema.json', '/$defs/ErrorCode/anyOf/0/enum', ERROR_CODES],
    ['error.schema.json', '/$defs/RepositoryUnavailableDetails/properties/reason/enum', REPOSITORY_UNAVAILABLE_REASONS],
    ['location.schema.json', '/properties/target/enum', LOCATION_TARGETS],
    ['repository.schema.json', '/$defs/Sync/properties/state/enum', SYNC_STATES],
    ['commit-diff.schema.json', '/$defs/CommitDiffFile/properties/status/enum', COMMIT_DIFF_FILE_STATUSES],
    ['carry-over-result.schema.json', '/$defs/CarryOverOutcome/enum', CARRY_OVER_OUTCOMES],
    ['tree-entry.schema.json', '/properties/type/enum', TREE_ENTRY_TYPES],
    ['file-content.schema.json', '/properties/type/enum', FILE_CONTENT_TYPES],
    ['draft.schema.json', '/properties/kind/enum', DRAFT_KINDS],
    ['api-token.schema.json', '/properties/scope/enum', API_TOKEN_SCOPES],
    ['api-token.schema.json', '/properties/state/enum', API_TOKEN_STATES],
    ['me.schema.json', '/properties/user/properties/signInMethod/enum', SIGN_IN_METHODS],
    ['inputs.schema.json', '/$defs/CreateDraftInput/properties/kind/enum', DRAFT_KINDS],
];

/** Keys of these keywords are names, not keywords. */
const NAME_MAP_KEYWORDS = new Set(['$defs', 'properties', 'patternProperties', 'dependentSchemas']);
/** Values of these keywords are data, not schemas. `if` holds conditions, not types. */
const SKIPPED_KEYWORDS = new Set(['enum', 'const', 'default', 'examples', 'if']);

function escapeToken(token: string): string {
    return token.replaceAll('~', '~0').replaceAll('/', '~1');
}

function isObject(value: Json | undefined): value is { [key: string]: Json } {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function collectEnumPointers(schema: Json, pointer: string, found: string[]): void {
    if (Array.isArray(schema)) {
        schema.forEach((item, index) => collectEnumPointers(item, `${pointer}/${index}`, found));

        return;
    }
    if (!isObject(schema)) {
        return;
    }
    for (const [keyword, value] of Object.entries(schema)) {
        const child = `${pointer}/${escapeToken(keyword)}`;
        if ('enum' === keyword) {
            found.push(child);
        }
        if (SKIPPED_KEYWORDS.has(keyword)) {
            continue;
        }
        if (NAME_MAP_KEYWORDS.has(keyword) && isObject(value)) {
            for (const [name, subschema] of Object.entries(value)) {
                collectEnumPointers(subschema, `${child}/${escapeToken(name)}`, found);
            }
            continue;
        }
        collectEnumPointers(value, child, found);
    }
}

function resolvePointer(document: Json, pointer: string): Json | undefined {
    let node: Json | undefined = document;
    for (const token of pointer.split('/').slice(1)) {
        const key = token.replaceAll('~1', '/').replaceAll('~0', '~');
        node = Array.isArray(node) ? node[Number(key)] : isObject(node) ? node[key] : undefined;
    }

    return node;
}

describe('ContractEnumsTest', () => {
    it('Each enum array equals its enum in docs/specs', () => {
        for (const [schemaFileName, jsonPointer, tsArray] of ENUM_ARRAYS) {
            const schema = schemas.get(schemaFileName);
            expect(schema, schemaFileName).toBeDefined();
            expect(resolvePointer(schema ?? null, jsonPointer), `${schemaFileName}#${jsonPointer}`).toEqual(tsArray);
        }

        const schemaPointers = [...schemas].flatMap(([schemaFileName, schema]) => {
            const found: string[] = [];
            collectEnumPointers(schema, '', found);

            return found.map((pointer) => `${schemaFileName}#${pointer}`);
        });
        const tablePointers = ENUM_ARRAYS.map(([schemaFileName, jsonPointer]) => `${schemaFileName}#${jsonPointer}`);
        expect(tablePointers.toSorted()).toEqual(schemaPointers.toSorted());
    });
});
