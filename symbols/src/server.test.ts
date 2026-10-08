import { type ChildProcessWithoutNullStreams, spawn } from 'node:child_process';
import { once } from 'node:events';
import { createServer } from 'node:net';
import { fileURLToPath } from 'node:url';
import { afterEach, describe, expect, it } from 'vitest';

type Service = { process: ChildProcessWithoutNullStreams; exited: Promise<[code: number | null, signal: string | null]>; stderr: () => string };

const SERVER = fileURLToPath(new URL('./server.ts', import.meta.url));

const services: Service[] = [];

/** Empty variables mean the defaults. */
function start(variables: Record<string, string>): Service {
    const child = spawn(process.execPath, [SERVER], { env: { ...process.env, SYMBOLS_HOST: '', SYMBOLS_PORT: '', ...variables } });
    let stderr = '';
    child.stderr.on('data', (chunk: Buffer) => (stderr += chunk.toString()));
    const service: Service = { process: child, exited: once(child, 'exit') as Service['exited'], stderr: () => stderr };
    services.push(service);

    return service;
}

/** The host and the port of the start line. */
async function listening({ process: child }: Service): Promise<{ host: string; port: number }> {
    let stdout = '';

    for await (const chunk of child.stdout) {
        stdout += (chunk as Buffer).toString();
        const [, host, port] = /^Listening on (.+):(\d+): /m.exec(stdout) ?? [];

        if (undefined !== host && undefined !== port) {
            return { host, port: Number(port) };
        }
    }

    throw new Error(`The service stopped before it listened: ${stdout}`);
}

async function freePort(): Promise<number> {
    const probe = createServer().listen(0, '127.0.0.1');
    await once(probe, 'listening');
    const { port } = probe.address() as { port: number };
    probe.close();
    await once(probe, 'close');

    return port;
}

async function health(port: number): Promise<number> {
    return (await fetch(`http://127.0.0.1:${port}/healthz`)).status;
}

afterEach(async () => {
    await Promise.all(
        services.splice(0).map(async (service) => {
            service.process.kill();
            await service.exited;
        }),
    );
});

describe('ServerTest', { timeout: 20_000 }, () => {
    it('Without SYMBOLS_HOST, the service listens on 127.0.0.1', async () => {
        const { host, port } = await listening(start({ SYMBOLS_PORT: '0' }));

        expect(host).toBe('127.0.0.1');
        expect(await health(port)).toBe(200);
    });

    it('SYMBOLS_HOST and SYMBOLS_PORT set the listen address', async () => {
        const free = await freePort();
        const { host, port } = await listening(start({ SYMBOLS_HOST: '0.0.0.0', SYMBOLS_PORT: String(free) }));

        expect([host, port]).toEqual(['0.0.0.0', free]);
        expect(await health(port)).toBe(200);
    });

    it('An invalid SYMBOLS_PORT stops the service with exit code 1', async () => {
        const service = start({ SYMBOLS_PORT: 'http' });
        const [code] = await service.exited;

        expect(code).toBe(1);
        expect(service.stderr()).toContain('ERR_SOCKET_BAD_PORT');
    });
});
