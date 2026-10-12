import { useCallback, useMemo } from 'react';
import { useLanguage } from '../contexts/LanguageContext';
import { translate, translateValue, isRtlLocale } from './translate';

export { translate, translateValue, isRtlLocale, dictionaries } from './translate';

/** Service Tracker strings (en + ar). Follows the app's LanguageContext locale; independent of the app-wide i18n tables. */
export function useServiceT() {
    const { locale } = useLanguage();
    const t = useCallback((key: string, params?: Record<string, string | number>) => translate(locale, key, params), [locale]);
    const tv = useCallback((group: string, value: string | null | undefined) => translateValue(locale, group, value), [locale]);
    return useMemo(() => ({ t, tv, locale, isRTL: isRtlLocale(locale) }), [t, tv, locale]);
}
