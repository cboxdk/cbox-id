import { useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Button, Field, Input, PageHeader, Panel } from '@/ui';
import { update } from '@actions/App/Http/Controllers/Console/AccountSettingsController';

type Props = PageProps<{ name: string }>;

export default function AccountSettings({ name }: Props) {
    const form = useForm({ name });

    return (
        <>
            <PageHeader description="The name of the account these identity providers are billed and administered under." />

            <form
                className="mt-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch(update.url());
                }}
            >
                <Panel>
                    <div className="flex items-start gap-2">
                        <Field
                            label="Account name"
                            hint="Shown across the console."
                            error={form.errors.name}
                            className="flex-1 max-w-sm"
                        >
                            <Input
                                name="name"
                                placeholder="Acme Inc."
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>

                        <Button
                            type="submit"
                            variant="primary"
                            loading={form.processing}
                            style={{ marginTop: '1.9rem' }}
                        >
                            Save
                        </Button>
                    </div>
                </Panel>
            </form>

            {/*
                DELETION IS NOT A BUTTON, and its absence is the design. Deleting an
                account tears down every project and environment it owns — live identity
                providers other people's users are signing in through — so it is a
                conversation, not a control somebody would then have to be talked out of.
            */}
            <div className="mt-4">
                <Panel title="Delete account">
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        Deleting this account tears down every project and environment it owns.
                        To protect live identity providers this isn't self-serve — contact
                        support to proceed.
                    </p>
                </Panel>
            </div>
        </>
    );
}

AccountSettings.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
