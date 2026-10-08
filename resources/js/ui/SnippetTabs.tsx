import { type ReactNode, useState } from 'react';
import { CodeBlock } from './CodeBlock';
import { Icon } from './Icon';
import { Tab, TabPanel, Tabs } from './Tabs';

export interface CodeSnippet {
    id: string;
    /** The tab's name: "curl", "Laravel", "MCP". */
    label: string;
    code: string;
    install?: string | null;
    /** The SDK's reference, opened in a new tab. */
    docs?: string | null;
    /** A line under the code: a caveat, where it goes. */
    note?: ReactNode;
}

export interface SnippetTabsProps {
    snippets: CodeSnippet[];
    /** Names the set of tabs for assistive technology — "SDK examples", "API equivalent". */
    label: string;
    /** Controlled, for a page that remembers the reader's choice. */
    value?: string;
    onValueChange?: (value: string) => void;
}

/**
 * The same thing written several ways — one tab per SDK, or per door into the API — with
 * the install line, the code, a copy button and the reference link in every panel.
 *
 * Was the app page's private `SnippetPanel`; the "</> API" disclosure and the quickstart
 * needed the same panel, and three copies of a code block drift into three looks.
 */
export function SnippetTabs({ snippets, label, value, onValueChange }: SnippetTabsProps) {
    const [own, setOwn] = useState(snippets[0]?.id ?? '');
    const current = value ?? own;
    const change = onValueChange ?? setOwn;

    const first = snippets[0];

    if (first === undefined) {
        return null;
    }

    return (
        <Tabs
            value={snippets.some((snippet) => snippet.id === current) ? current : first.id}
            onValueChange={change}
            label={label}
            panels={snippets.map((snippet) => (
                <TabPanel key={snippet.id} value={snippet.id}>
                    <SnippetPanel snippet={snippet} />
                </TabPanel>
            ))}
        >
            {snippets.map((snippet) => (
                <Tab key={snippet.id} value={snippet.id}>
                    {snippet.label}
                </Tab>
            ))}
        </Tabs>
    );
}

function SnippetPanel({ snippet }: { snippet: CodeSnippet }) {
    return (
        <div>
            <CodeBlock
                code={snippet.code}
                install={snippet.install}
                copyLabel={`Copy ${snippet.label}`}
                caption={snippet.note}
            />

            {snippet.docs != null && snippet.docs !== '' && (
                <p className="mt-2 text-xs">
                    {/*
                        A new tab: this is a reference opened WHILE wiring something up, often
                        on a screen that shows a secret exactly once. Navigating away from it
                        is how the secret is lost.
                    */}
                    <a
                        href={snippet.docs}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="underline"
                        style={{ color: 'var(--accent-strong)' }}
                    >
                        {snippet.label} SDK reference
                        <Icon name="external" className="w-3 h-3 inline ml-0.5" />
                    </a>
                </p>
            )}
        </div>
    );
}
