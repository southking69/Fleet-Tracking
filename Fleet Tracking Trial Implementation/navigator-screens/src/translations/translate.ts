/** Pure translation helpers (no React) so they can be unit tested. */
import { slug } from '../services/visitModel';
import en from './serviceTracker.en.json';
import ar from './serviceTracker.ar.json';

type Dict = { [k: string]: string | Dict };
export const dictionaries: Record<string, Dict> = { en: en as Dict, ar: ar as Dict };
const RTL = new Set(['ar', 'he', 'fa', 'ur']);
const langOf = (locale: string) => (locale || 'en').split(/[-_]/)[0];

function lookup(dict: Dict | undefined, key: string): string | undefined {
    let cur: any = dict;
    for (const part of key.split('.')) {
        if (cur == null || typeof cur !== 'object') return undefined;
        cur = cur[part];
    }
    return typeof cur === 'string' ? cur : undefined;
}

export function translate(locale: string, key: string, params?: Record<string, string | number>): string {
    const raw = lookup(dictionaries[langOf(locale)], key) ?? lookup(dictionaries.en, key) ?? key;
    return params ? raw.replace(/\{(\w+)\}/g, (_m, k) => (params[k] != null ? String(params[k]) : `{${k}}`)) : raw;
}

/** Translate a stored English value (e.g. severity 'Minor', 'Pre-existing') under `group`; falls back to the raw value. */
export function translateValue(locale: string, group: string, value: string | null | undefined): string {
    if (!value) return '';
    const k = `${group}.${slug(value)}`;
    return lookup(dictionaries[langOf(locale)], k) ?? lookup(dictionaries.en, k) ?? value;
}

export const isRtlLocale = (locale: string) => RTL.has(langOf(locale));
