// The postinstall of package.json. D82: a fetcher redirect aborts a load that is still running.
import { readFileSync, writeFileSync } from 'node:fs';

const VERSION = '8.4.0';
const ANCHOR = '\t\tpendingNavigationController = null;\n\t\tlet redirectNavigationType = ';
const PATCHED = '\t\tif (isNavigation) pendingNavigationController = null;\n\t\tlet redirectNavigationType = ';
const root = new URL('../node_modules/react-router/', import.meta.url);
const { version } = JSON.parse(readFileSync(new URL('package.json', root), 'utf8'));

if (VERSION !== version) {
    throw new Error(`bin/patch-react-router.mjs patches react-router ${VERSION}, not ${version}. Check D82, then update or remove the patch.`);
}

for (const build of ['development', 'production']) {
    const file = new URL(`dist/${build}/lib/router/router.js`, root);
    const source = readFileSync(file, 'utf8');

    if (source.includes(PATCHED)) {
        continue;
    }
    if (2 !== source.split(ANCHOR).length) {
        throw new Error(`The anchor of the React Router patch is not in ${file.pathname} exactly once.`);
    }
    writeFileSync(file, source.replace(ANCHOR, PATCHED));
}
