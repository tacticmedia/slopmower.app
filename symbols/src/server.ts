import { createServer, type IncomingMessage, type ServerResponse } from 'node:http';
import type { AddressInfo } from 'node:net';
import { extract } from './extract.ts';
import { loadLanguages } from './languages.ts';

/** The `symbols` image sets `0.0.0.0`. */
const HOST = process.env.SYMBOLS_HOST || '127.0.0.1';

/** Node refuses an invalid port, so the service stops. */
const PORT = Number(process.env.SYMBOLS_PORT || 3000);

/** The V7 limit of a renderable file. */
const MAX_BLOB_BYTES = 1_048_576;

const languages = await loadLanguages();

function send(response: ServerResponse, status: number, body: unknown): void {
    response.writeHead(status, { 'Content-Type': 'application/json' }).end(JSON.stringify(body));
}

/** Null when the body exceeds MAX_BLOB_BYTES. */
async function readBlob(request: IncomingMessage): Promise<Buffer | null> {
    if (Number(request.headers['content-length'] ?? 0) > MAX_BLOB_BYTES) {
        return null;
    }

    const chunks: Buffer[] = [];
    let sizeBytes = 0;

    for await (const chunk of request) {
        const buffer = chunk as Buffer;
        sizeBytes += buffer.length;

        if (sizeBytes > MAX_BLOB_BYTES) {
            return null;
        }

        chunks.push(buffer);
    }

    return Buffer.concat(chunks);
}

async function handle(request: IncomingMessage, response: ServerResponse): Promise<void> {
    const url = new URL(request.url ?? '/', 'http://symbols');

    if ('GET' === request.method && '/healthz' === url.pathname) {
        send(response, 200, { status: 'ok' });

        return;
    }

    if ('POST' !== request.method || '/v1/symbols' !== url.pathname) {
        send(response, 404, { error: 'Not found.' });

        return;
    }

    const language = languages.get(url.searchParams.get('language') ?? '');

    if (undefined === language) {
        send(response, 422, { error: `Unknown language. Known: ${[...languages.keys()].join(', ')}.` });

        return;
    }

    const blob = await readBlob(request);

    if (null === blob) {
        response.setHeader('Connection', 'close');
        send(response, 413, { error: `The blob exceeds ${MAX_BLOB_BYTES} bytes.` });

        return;
    }

    send(response, 200, extract(language, new TextDecoder().decode(blob)));
}

const server = createServer((request, response) => {
    handle(request, response).catch((error: unknown) => {
        console.error(error);

        if (!response.headersSent) {
            send(response, 500, { error: 'The extraction failed.' });
        }
    });
});

for (const signal of ['SIGINT', 'SIGTERM']) {
    process.on(signal, () => server.close());
}

server.listen(PORT, HOST, () => {
    const { address, family, port } = server.address() as AddressInfo;
    const host = 'IPv6' === family ? `[${address}]` : address;
    console.log(`Listening on ${host}:${port}: ${[...languages.keys()].join(', ')}.`);
});
