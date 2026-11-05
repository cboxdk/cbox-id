import { useTranslator } from '@/i18n';
import { router, useForm, useRemember } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { Button, Field, Input, Panel } from '@/ui';

export interface UserProfileData {
    id: string;
    name: string | null;
    email: string;
}

/** A read-first profile, shared by the list panel and the full page. */
export function UserProfile({ user, href }: { user: UserProfileData; href: string }) {
    const { t } = useTranslator();
    const form = useForm(`user-profile:${user.id}`, { name: user.name ?? '', email: user.email });
    const [editing, setEditing] = useRemember<'name' | 'email' | null>(
        null,
        `user-profile-field:${user.id}`,
    );
    const { reset, isDirty } = form;
    const input = useRef<HTMLInputElement>(null);
    const editButtons = useRef<Partial<Record<'name' | 'email', HTMLButtonElement | null>>>({});

    useEffect(() => {
        if (editing) input.current?.focus();
    }, [editing]);

    // Drafts are remembered on Back. Ordinary navigation asks before discarding them.
    useEffect(() => {
        if (!editing || !isDirty) return;
        const unload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', unload);
        const stop = router.on('before', (event) => {
            if (event.detail.visit.method !== 'get') return;
            if (!window.confirm(t('console.ux_discard_changes'))) {
                event.preventDefault();
            } else {
                reset();
                setEditing(null);
            }
        });
        return () => {
            window.removeEventListener('beforeunload', unload);
            stop();
        };
    }, [editing, isDirty, reset, setEditing, t]);

    const close = () => {
        form.reset();
        form.clearErrors();
        setEditing(null);
        requestAnimationFrame(() => {
            if (editing) editButtons.current[editing]?.focus();
        });
    };

    return (
        <Panel title={t('console.profile_d696a35b')}>
            <div className="divide-y divide-[var(--border)]">
                {(['name', 'email'] as const).map((field) => {
                    const label = t(
                        field === 'name' ? 'console.name_dcd1d522' : 'console.email_969ccbd3',
                    );
                    return (
                        <div key={field} className="py-4 first:pt-0 last:pb-0">
                            {editing === field ? (
                                <form
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        form.transform((data) => ({ [field]: data[field] }));
                                        form.patch(href, {
                                            preserveScroll: true,
                                            onSuccess: () => {
                                                form.setDefaults();
                                                setEditing(null);
                                                requestAnimationFrame(() => {
                                                    if (editing)
                                                        editButtons.current[editing]?.focus();
                                                });
                                            },
                                        });
                                    }}
                                >
                                    <Field
                                        label={label}
                                        optional={field === 'name'}
                                        error={form.errors[field]}
                                        hint={
                                            field === 'email'
                                                ? t(
                                                      'console.also_their_recovery_channel_changing_it_clea_a46b162f',
                                                  )
                                                : undefined
                                        }
                                    >
                                        <Input
                                            ref={input}
                                            name={field}
                                            type={field === 'email' ? 'email' : 'text'}
                                            value={form.data[field]}
                                            maxLength={190}
                                            required={field === 'email'}
                                            onChange={(event) =>
                                                form.setData(field, event.target.value)
                                            }
                                        />
                                    </Field>
                                    <div className="mt-3 flex gap-2">
                                        <Button
                                            type="submit"
                                            variant="primary"
                                            size="sm"
                                            loading={form.processing}
                                            disabled={!form.isDirty}
                                        >
                                            {t('console.save_1509f561')}
                                        </Button>
                                        <Button
                                            size="sm"
                                            disabled={form.processing}
                                            onClick={close}
                                        >
                                            {t('console.cancel_19766ed6')}
                                        </Button>
                                    </div>
                                </form>
                            ) : (
                                <div className="flex items-start gap-4">
                                    <div className="min-w-0 flex-1">
                                        <p className="text-xs text-[var(--muted-foreground)]">
                                            {label}
                                        </p>
                                        <p className="mt-1 text-sm break-words">
                                            {user[field] || '—'}
                                        </p>
                                    </div>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        disabled={editing !== null}
                                        ref={(button) => {
                                            editButtons.current[field] = button;
                                        }}
                                        aria-label={`${t('console.edit_464c4ffd')} ${label}`}
                                        onClick={() => {
                                            form.setData({
                                                name: user.name ?? '',
                                                email: user.email,
                                            });
                                            form.setDefaults({
                                                name: user.name ?? '',
                                                email: user.email,
                                            });
                                            setEditing(field);
                                        }}
                                    >
                                        {t('console.edit_464c4ffd')}
                                    </Button>
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>
        </Panel>
    );
}
