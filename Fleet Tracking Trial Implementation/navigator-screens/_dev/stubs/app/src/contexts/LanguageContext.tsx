export const useLanguage = (): { locale: string; setLocale: (l: string) => void; t: (k: string) => string } => ({ locale: 'en', setLocale: () => {}, t: (k) => k });
