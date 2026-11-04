import assert from 'node:assert/strict';
import { test } from 'node:test';
import { includeQueryMethod, normalizeDictionaries } from './wayfinder-compat.mjs';

test('coalesces identical URI aliases without removing distinct routes', () => {
    const input =
        "const Controller = {\n    '/apps': Apps,\n    '/apps': Apps,\n    '/apps/{id}': App,\n}\n";
    const expected = "const Controller = {\n    '/apps': Apps,\n    '/apps/{id}': App,\n}\n";
    assert.equal(normalizeDictionaries(input), expected);
    assert.equal(normalizeDictionaries(expected), expected);
});
test('refuses competing handlers rather than silently changing a route', () => {
    assert.throws(
        () =>
            normalizeDictionaries(
                "const Controller = {\n    '/apps': First,\n    '/apps': Second,\n}\n",
            ),
        /Conflicting generated route/,
    );
});
test('adds QUERY once and preserves the other method types', () => {
    const source = 'type Method = "get" | "post";';
    const expected = 'type Method = "get" | "post" | "query";';
    assert.equal(includeQueryMethod(source), expected);
    assert.equal(includeQueryMethod(expected), expected);
});
