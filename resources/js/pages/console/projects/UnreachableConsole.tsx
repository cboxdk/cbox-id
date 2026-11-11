import { useShellHelp } from '@cboxdk/cbox-ui';
import { useTranslator } from '@/i18n';
import { Button } from '@/ui';

/**
 * In place of "Open console" for an environment whose console has no address (it is in no
 * project of an organization, {@see \App\Platform\Console\EnvironmentConsolePath}): the row
 * stays, says why, and offers the next step, rather than a link that ends in a 404.
 */
export function UnreachableConsole({ name }: { name: string }) {
    const { t } = useTranslator();
    const help = useShellHelp();

    return (
        <div className="shrink-0 max-w-xs text-right" role="note">
            <p className="text-sm font-medium">{t('console.environment_console_unresolved')}</p>
            <p className="mt-0.5 text-xs" style={{ color: 'var(--faint)' }}>
                {t('console.environment_console_unresolved_detail')}
            </p>
            {help !== null && (
                <Button
                    size="sm"
                    variant="secondary"
                    className="mt-2"
                    onClick={() =>
                        help.ask(t('console.environment_console_unresolved_question', { name }))
                    }
                >
                    {t('console.environment_console_unresolved_ask')}
                </Button>
            )}
        </div>
    );
}
