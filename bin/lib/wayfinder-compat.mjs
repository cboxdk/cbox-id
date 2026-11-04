/** Compatibility with the locked Wayfinder generator and Laravel's QUERY verb. */
export function normalizeDictionaries(source) {
    return source.replace(
        /((?:export )?const \w+ = \{\n)((?:    ['"][^'"\n]+['"]: \w+,\n)+)(\})/g,
        (_block, start, entries, end) => {
            const seen = new Map();
            const lines = entries
                .trimEnd()
                .split('\n')
                .filter((line) => {
                    const [, uri, target] = line.match(/^    (['"][^'"\n]+['"]): (\w+),$/);
                    if (seen.has(uri)) {
                        if (seen.get(uri) !== target)
                            throw new Error(`Conflicting generated route dictionary entry: ${uri}`);
                        return false;
                    }
                    seen.set(uri, target);
                    return true;
                });
            return start + lines.join('\n') + '\n' + end;
        },
    );
}

export function includeQueryMethod(source) {
    return source.replace(/type Method = ([^;]+);/, (declaration, methods) =>
        /['"]query['"]/.test(methods) ? declaration : `type Method = ${methods} | "query";`,
    );
}
