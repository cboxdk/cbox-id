import { useState } from 'react';
import { CatalogAppIcon } from '@/chrome/AppSwitcher';
import AuthLayout from '@/layouts/AuthLayout';
import type { CatalogApp, PageProps } from '@/types';
import { Input } from '@/ui';

export default function Apps({ apps }: PageProps<{ apps: CatalogApp[] }>) {
    const [query, setQuery] = useState('');
    const visible = apps.filter((app) =>
        `${app.name} ${app.description ?? ''}`.toLowerCase().includes(query.toLowerCase()),
    );
    return (
        <div className="w-full max-w-md mx-auto">
            <h1 className="text-2xl font-semibold mb-4">Switch app</h1>
            <Input
                autoFocus
                aria-label="Search apps"
                placeholder="Search apps…"
                value={query}
                onChange={(event) => setQuery(event.target.value)}
            />
            <nav aria-label="Apps" className="mt-3 max-h-96 overflow-y-auto">
                {visible.map((app) => (
                    <a
                        key={app.key}
                        href={app.launch_url}
                        className="flex items-center gap-3 rounded-lg p-3 hover:bg-[var(--muted)] focus-visible:ring-2 focus-visible:ring-[var(--ring)]"
                    >
                        <CatalogAppIcon app={app} />
                        <span>
                            <span className="block font-medium">{app.name}</span>
                            <span className="text-sm text-[var(--muted-foreground)]">
                                {app.description}
                            </span>
                        </span>
                    </a>
                ))}
                {visible.length === 0 && (
                    <p className="py-4 text-sm">
                        {apps.length === 0
                            ? 'No apps have been published for you yet.'
                            : 'No apps match your search.'}
                    </p>
                )}
            </nav>
        </div>
    );
}
Apps.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
