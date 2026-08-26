import { router } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import { Button, EmptyState, Icon, PageHeader } from '@/ui';

interface Request {
    id: string;
    app: string;
    bindingMessage: string | null;
    scopes: { value: string; label: string }[];
    urls: { approve: string; deny: string };
}

type Props = PageProps<{
    requests: Request[];
    help: HelpContent;
}>;

export default function Approvals({ requests, help }: Props) {
    return (
        <div style={{ maxWidth: '32rem' }}>
            <PageHeader
                help={help}
                description="When an app or agent needs your go-ahead to act as you, it asks here. Approve only requests you started yourself."
            />

            {requests.length === 0 ? (
                <div className="mt-6">
                    <EmptyState
                        icon="shield"
                        title="Nothing waiting for you"
                        description="Requests appear here on their own when an app or agent needs your approval to act as you. Nothing to do until one does — and if a request shows up you did not start, deny it."
                    />
                </div>
            ) : (
                <div className="mt-6">
                    {requests.map((request) => (
                        <RequestCard key={request.id} request={request} />
                    ))}
                </div>
            )}
        </div>
    );
}

function RequestCard({ request }: { request: Request }) {
    // A busy state on BOTH buttons at once: the two are opposite answers to one question,
    // and a second click landing on the other one while the first is in flight is the worst
    // possible race for a control that grants access.
    const [answering, setAnswering] = useState(false);

    const answer = (url: string): void => {
        router.post(
            url,
            {},
            {
                preserveScroll: true,
                onStart: () => setAnswering(true),
                onFinish: () => setAnswering(false),
            },
        );
    };

    return (
        <div className="card p-5 mb-4">
            <div className="flex items-center gap-3">
                <span
                    className="grid place-items-center rounded-full shrink-0"
                    style={{
                        width: '2.25rem',
                        height: '2.25rem',
                        background: 'var(--accent-soft)',
                        color: 'var(--accent-strong)',
                    }}
                    aria-hidden="true"
                >
                    <Icon name="shield" className="w-5 h-5" />
                </span>
                <div className="min-w-0">
                    <p className="font-medium truncate">{request.app} is requesting access</p>
                    <p className="text-xs" style={{ color: 'var(--faint)' }}>
                        wants to act on your behalf
                    </p>
                </div>
            </div>

            {request.bindingMessage !== null && (
                <div
                    className="mt-5 rounded-lg px-3.5 py-3"
                    style={{ background: 'var(--accent-soft)' }}
                >
                    <p className="cbx-page-eyebrow">Confirm this matches your device</p>
                    <p className="mt-1 font-medium">{request.bindingMessage}</p>
                </div>
            )}

            {request.scopes.length > 0 && (
                <>
                    <p className="cbx-page-eyebrow mt-6">This will allow {request.app} to</p>
                    <ul className="mt-2.5 space-y-2">
                        {request.scopes.map((scope) => (
                            <li key={scope.value} className="flex items-center gap-2.5 text-sm">
                                <Icon
                                    name="check"
                                    className="w-4 h-4 shrink-0"
                                    style={{ color: 'var(--success-strong)' }}
                                    aria-hidden="true"
                                />
                                <span>{scope.label}</span>
                            </li>
                        ))}
                    </ul>
                </>
            )}

            <div className="mt-7 flex gap-2.5">
                <Button
                    size="lg"
                    className="flex-1"
                    disabled={answering}
                    onClick={() => answer(request.urls.deny)}
                >
                    Deny
                </Button>
                <Button
                    size="lg"
                    variant="primary"
                    className="flex-1"
                    loading={answering}
                    onClick={() => answer(request.urls.approve)}
                >
                    Approve
                </Button>
            </div>
        </div>
    );
}

Approvals.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
