#!/usr/bin/env node
/* eslint-disable no-await-in-loop -- Each worker waits to keep concurrency at the requested bound. */
import { parseArgs } from 'node:util';
import { performance } from 'node:perf_hooks';

const { values } = parseArgs({
    options: {
        url: { type: 'string' },
        requests: { type: 'string', default: '200' },
        concurrency: { type: 'string', default: '10' },
        'p95-ms': { type: 'string', default: '500' },
    },
});
const url = new URL(values.url ?? '');
if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password)
    throw new Error('Supply an HTTP(S) URL without credentials.');
if (
    process.env.CBOX_READ_PROBE_TOKEN &&
    url.protocol !== 'https:' &&
    !['localhost', '127.0.0.1', '[::1]'].includes(url.hostname)
)
    throw new Error('Authenticated probes require HTTPS outside loopback.');
const count = Number(values.requests),
    concurrency = Number(values.concurrency),
    budget = Number(values['p95-ms']);
if (
    !Number.isInteger(count) ||
    count < 1 ||
    count > 10000 ||
    !Number.isInteger(concurrency) ||
    concurrency < 1 ||
    concurrency > 100 ||
    !Number.isFinite(budget) ||
    budget <= 0
)
    throw new Error('Requests: 1–10000; concurrency: 1–100; p95-ms: positive.');
const samples = [],
    statuses = {};
let cursor = 0,
    errors = 0;
const started = performance.now();
await Promise.all(
    Array.from({ length: Math.min(concurrency, count) }, async () => {
        while (cursor++ < count) {
            const start = performance.now();
            try {
                const response = await fetch(url, {
                    redirect: 'manual',
                    signal: AbortSignal.timeout(10000),
                    headers: process.env.CBOX_READ_PROBE_TOKEN
                        ? { Authorization: `Bearer ${process.env.CBOX_READ_PROBE_TOKEN}` }
                        : {},
                });
                await response.arrayBuffer();
                statuses[response.status] = (statuses[response.status] ?? 0) + 1;
                if (!response.ok) errors++;
            } catch {
                errors++;
                statuses.transport_error = (statuses.transport_error ?? 0) + 1;
            }
            samples.push(performance.now() - start);
        }
    }),
);
samples.sort((a, b) => a - b);
const percentile = (p) =>
    Math.round(samples[Math.max(0, Math.ceil(samples.length * p) - 1)] * 100) / 100;
const elapsed = performance.now() - started;
const p95 = percentile(0.95);
console.log(
    JSON.stringify(
        {
            endpoint: url.origin + url.pathname,
            requests: count,
            concurrency,
            elapsed_ms: Math.round(elapsed),
            requests_per_second: Math.round((count / elapsed) * 100000) / 100,
            p50_ms: percentile(0.5),
            p95_ms: p95,
            p99_ms: percentile(0.99),
            errors,
            statuses,
            passed: errors === 0 && p95 <= budget,
        },
        null,
        2,
    ),
);
process.exitCode = errors === 0 && p95 <= budget ? 0 : 1;
