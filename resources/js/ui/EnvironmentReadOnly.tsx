import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useTranslator } from '@/i18n';
import type { PageProps } from '@/types';

export function useCanManageEnvironment(): boolean {
    return usePage<PageProps>().props.canManageEnvironment !== false;
}

/** Read-only controls remain visible so readers can inspect the current configuration. */
export function EnvironmentReadOnly({ children }: { children: ReactNode }) {
    const canManage = useCanManageEnvironment();
    const { t } = useTranslator();

    return (
        <fieldset disabled={!canManage} className="min-w-0 space-y-6">
            {!canManage && (
                <legend className="mb-4 text-sm font-medium">
                    {t('console.agent_preset_read_only')}
                </legend>
            )}
            {children}
        </fieldset>
    );
}
