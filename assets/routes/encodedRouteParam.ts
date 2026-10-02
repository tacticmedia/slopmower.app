import type { Params } from 'react-router';

export function encodedRouteParam(params: Params, name: string): string {
    const value = params[name];

    if (undefined === value) {
        throw new Error(`The route has no ${name} parameter.`);
    }

    return encodeURIComponent(value);
}
