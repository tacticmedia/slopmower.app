import { readdirSync, readFileSync } from 'node:fs';
import Ajv2020 from 'ajv/dist/2020.js';
import addFormats from 'ajv-formats';

const SCHEMA_BASE = 'https://holistic-review.invalid/schemas/v1/';

const SCHEMA_DIRECTORY = new URL('../../../docs/specs/schemas/', import.meta.url);

/** A file of docs/specs/schemas, optionally with a `$defs` fragment, as the endpoint index names it. */
export type SchemaRef = `${string}.schema.json` | `${string}.schema.json#/$defs/${string}`;

export class SchemaViolation extends Error {
    constructor(ref: SchemaRef, errors: string) {
        super(`The body does not validate against ${ref}: ${errors}`);
        this.name = 'SchemaViolation';
    }
}

// strictTypes asks for a `type` beside every `properties`, which the spec schemas leave out in `if` branches.
const ajv = new Ajv2020({ allErrors: true, strict: true, strictTypes: false });
addFormats(ajv);

for (const file of readdirSync(SCHEMA_DIRECTORY).filter((name) => name.endsWith('.schema.json'))) {
    ajv.addSchema(JSON.parse(readFileSync(new URL(file, SCHEMA_DIRECTORY), 'utf8')));
}

export function assertMatchesSchema(ref: SchemaRef, body: unknown): void {
    const validate = ajv.getSchema(`${SCHEMA_BASE}${ref}`);

    if (undefined === validate) {
        throw new Error(`docs/specs/schemas has no ${ref}.`);
    }

    if (!validate(body)) {
        throw new SchemaViolation(ref, ajv.errorsText(validate.errors));
    }
}
