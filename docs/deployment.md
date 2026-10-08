# Deployment outside Docker Compose

Docker Compose runs the `symbols` service in development. A deployment without Compose must install the service, run it, and connect the application to it.

## Symbols service

The service extracts the symbols of every [symbol language](architecture.md#symbol-languages) but PHP and Twig (D56). The web server and the Messenger worker both call it through the scoped client `symbols.client`. When the service is down, the index job stores no symbols for these languages, and a request that needs their symbols answers 500. PHP and Twig files do not use the service.

### Requirements

| Item | Value |
|---|---|
| Node.js | 24.12 or later, from `engines` in `symbols/package.json`. Node runs `symbols/src/server.ts` with type stripping, so there is no build step. |
| Files | `symbols/package.json`, `symbols/package-lock.json`, `symbols/src/`, and `symbols/queries/`, from the release of the PHP code. |
| Build tools | None. |
| Memory | About 150 MB resident after start, with the 14 languages loaded. |
| Disk | About 260 MB for `symbols/node_modules`. |
| Port | TCP 3000, from `PORT` in `symbols/src/server.ts`. No environment variable changes it. |

### Install

In the `symbols/` directory of the release:

```sh
npm ci --ignore-scripts --no-audit --no-fund
```

`--ignore-scripts` is necessary. The install scripts of the grammar packages compile Node bindings, and they fail without Python and a C compiler. The service loads only the `.wasm` grammars, so it does not need the bindings. `npm ci` installs the exact versions of `package-lock.json`. Run it for each release.

### Run

```sh
node symbols/src/server.ts
```

The service finds its grammars and queries relative to `src/server.ts`, so the working directory has no effect. `npm start` in `symbols/` runs the same command under an npm parent process. Run `node` directly, so that the process manager supervises and signals the service process itself.

Configure the process manager, for example a systemd unit, as follows:

- Run the service as an unprivileged user. The user needs read access to `symbols/` only. The service writes no files.
- Restart the service when it stops. It stops with exit code 1 when port 3000 is in use (`EADDRINUSE`), or when a grammar does not load.
- Stop it with `SIGTERM` or `SIGINT`. The server then accepts no new connections, and it stops when the open requests end.
- Start the web server and the worker after the service is healthy. The service loads all grammars before it listens, so the first 200 from `/healthz` means that it is ready. Compose waits for the same check.
- Collect stdout and stderr. The service writes one line at start with its languages, and a stack trace for each failed extraction.

Check the service:

```sh
curl -fsS http://127.0.0.1:3000/healthz
curl -fsS -H 'Content-Type: application/octet-stream' --data-binary @symbols/src/server.ts 'http://127.0.0.1:3000/v1/symbols?language=typescript'
```

The first command gives `{"status":"ok"}`. The second gives `"parseError":false` and a list of `symbols`. [Symbol languages](architecture.md#symbol-languages) gives the full contract.

### Network access

The service listens on all interfaces, `::` and on most systems also `0.0.0.0`. It has no authentication. Block TCP port 3000 for all clients but the hosts of the web server and the worker, with a firewall or a security group.

### Configure the application

Set `SYMBOLS_URL` for every PHP process: the web server and each Messenger worker.

| Where the service runs | `SYMBOLS_URL` |
|---|---|
| On the host of the PHP process | `http://127.0.0.1:3000` |
| On another host | `http://<host>:3000` |

- The `.env` default `http://symbols:3000` resolves only in the Compose network.
- Give the scheme, the host, and the port only. The client requests the absolute path `/v1/symbols`, so it drops a path in `SYMBOLS_URL`. With `http://proxy/symbols/`, the request goes to `http://proxy/v1/symbols`. A reverse proxy must serve the service at its root path.

From the host of each PHP process, run the `/healthz` check against the `SYMBOLS_URL` value.

### Deploy a new release

1. Install and restart the service before the web server and the worker. `TreeSitterSymbolExtractor::LANGUAGES` must list only languages that the service knows. The service answers 422 to an unknown language, and the request that needs the symbols answers 500.
2. A changed grammar or query file does not change the stored symbols. `app:index:rebuild <tenantId> <reviewId> --reparse` deletes the parsed blobs and symbols of the repository of the review, and queues the index job of the review. A review indexed before the release that added its language needs `app:index:rebuild <tenantId> <reviewId>`. See "Index version per language" in the [candidates](plan/features.md#candidates).
