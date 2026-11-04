import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import { ConsoleLanguagePicker } from '@/chrome/ConsoleLanguagePicker';
import { consoleI18n } from '@/test/catalogue';
import { setPageProps } from '@/test/page';
import { ConfirmDelete } from '@/ui/ConfirmDelete';
import { PasswordField } from '@/ui/PasswordField';
import { CopyButton } from '@/ui/CopyButton';
import { createTranslator } from './index';
import { UiTranslationProvider } from './ui';

function inDanish(children: React.ReactNode) {
    const { locale, messages } = consoleI18n('da');
    return (
        <UiTranslationProvider translator={createTranslator(locale, messages)}>
            {children}
        </UiTranslationProvider>
    );
}

describe('console languages', () => {
    it('switches language from a keyboard accessible topbar menu', async () => {
        setPageProps({ i18n: consoleI18n('en') });
        const post = vi.spyOn(router, 'post').mockImplementation(() => {});
        render(<ConsoleLanguagePicker />);
        screen.getByRole('button', { name: 'Language: English' }).focus();
        await userEvent.keyboard('{Enter}');
        expect(screen.getByRole('menuitemradio', { name: 'English' })).toHaveAttribute(
            'aria-checked',
            'true',
        );
        screen.getByRole('menuitemradio', { name: 'Dansk' }).focus();
        await userEvent.keyboard('{Enter}');
        expect(post).toHaveBeenCalledWith(
            '/console/locale',
            { locale: 'da' },
            { preserveScroll: true, preserveState: false },
        );
    });

    it('localizes control defaults without translating the value being copied', () => {
        render(
            inDanish(
                <>
                    <PasswordField label="Adgangskode" />
                    <CopyButton value="secret-DO-NOT-TRANSLATE" />
                </>,
            ),
        );
        expect(screen.getByRole('button', { name: 'Vis adgangskode' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Kopiér' })).toBeInTheDocument();
    });

    it('retains exact typed-name confirmation when the destructive dialog is Danish', async () => {
        const confirm = vi.fn<() => void>();
        const name = 'Delete <Acme> & production';
        render(
            inDanish(
                <ConfirmDelete open onOpenChange={() => {}} name={name} onConfirm={confirm} />,
            ),
        );
        const button = screen.getByRole('button', { name: 'Slet' });
        expect(button).toBeDisabled();
        await userEvent.type(screen.getByRole('textbox'), name.toLowerCase());
        expect(button).toBeDisabled();
        await userEvent.clear(screen.getByRole('textbox'));
        await userEvent.type(screen.getByRole('textbox'), name);
        expect(button).toBeEnabled();
        await userEvent.click(button);
        expect(confirm).toHaveBeenCalledOnce();
    });
});

it.each([
    ['de', 'Speichern'],
    ['sv', 'Spara'],
    ['nb', 'Lagre'],
    ['fi', 'Tallenna'],
    ['fr', 'Enregistrer'],
    ['es', 'Guardar'],
    ['it', 'Salva'],
    ['nl', 'Opslaan'],
] as const)('reads the real %s catalogue and preserves interpolated user data', (locale, save) => {
    const { messages } = consoleI18n(locale);
    const translator = createTranslator(locale, messages);
    expect(translator.t('console.save_1509f561')).toBe(save);
    const name = 'Delete <Acme> & :unknown';
    expect(translator.t('console.account_menu_value0_6ab6a9a2', { value0: name })).toContain(name);
});
