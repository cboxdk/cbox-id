import { createContext, useContext, useMemo, type ReactNode } from 'react';
import { interpolate, type MessageKey, type Params, type Translator } from './index';

const UiTranslations = createContext<Translator | null>(null);

/** Console defaults; hosted forms can continue to supply their own translated labels. */
export function UiTranslationProvider({
    translator,
    children,
}: {
    translator: Translator;
    children: ReactNode;
}) {
    return <UiTranslations.Provider value={translator}>{children}</UiTranslations.Provider>;
}

/** Controls also work independently of Inertia, with their English defaults. */
export function useUiTranslator() {
    const translator = useContext(UiTranslations);
    return useMemo(
        () => ({
            locale: translator?.locale ?? 'en',
            t: (key: MessageKey, fallback: string, params?: Params) =>
                translator === null ? interpolate(fallback, params) : translator.t(key, params),
        }),
        [translator],
    );
}
