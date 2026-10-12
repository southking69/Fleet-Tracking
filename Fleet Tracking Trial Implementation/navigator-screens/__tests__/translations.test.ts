import fs from 'fs';
import path from 'path';
import en from '../src/translations/serviceTracker.en.json';
import ar from '../src/translations/serviceTracker.ar.json';
import { translate, translateValue, isRtlLocale } from '../src/translations/translate';
import { INSPECTION_ANGLES, DEFAULT_LISTS } from '../src/services/visitModel';

const flat = (o: any, p = ''): string[] => Object.entries(o).flatMap(([k, v]) => (typeof v === 'string' ? [p + k] : flat(v, p + k + '.')));
const SRC = path.resolve(__dirname, '../src');
const walk = (d: string): string[] => fs.readdirSync(d, { withFileTypes: true }).flatMap((e) => (e.isDirectory() ? walk(path.join(d, e.name)) : /\.tsx?$/.test(e.name) ? [path.join(d, e.name)] : []));

describe('translations', () => {
    const enKeys = new Set(flat(en)), arKeys = new Set(flat(ar));
    test('en and ar have identical key sets', () => {
        expect([...enKeys].filter((k) => !arKeys.has(k))).toEqual([]);
        expect([...arKeys].filter((k) => !enKeys.has(k))).toEqual([]);
    });
    test('every {placeholder} in en exists in ar', () => {
        for (const k of enKeys) {
            const ph = (s: string) => (s.match(/\{\w+\}/g) || []).sort().join();
            expect([k, ph(translate('ar', k))]).toEqual([k, ph(translate('en', k))]);
        }
    });
    test('every literal t(\'...\') key used in the code exists', () => {
        const used = new Set<string>();
        for (const f of walk(SRC)) {
            const src = fs.readFileSync(f, 'utf8');
            for (const m of src.matchAll(/\bt\('([A-Za-z0-9_.]+)'/g)) used.add(m[1]);
            for (const m of src.matchAll(/(?:labelKey|key): '([a-z]+\.[A-Za-z.]+)'/g)) used.add(m[1]);
            for (const m of src.matchAll(/header\('([a-zA-Z.]+)'/g)) used.add(m[1]);
        }
        expect(used.size).toBeGreaterThan(80);
        expect([...used].filter((k) => !enKeys.has(k))).toEqual([]);
    });
    test('dynamic key families are complete', () => {
        for (const a of INSPECTION_ANGLES) expect(enKeys.has(`inspection.angle.${a}`)).toBe(true);
        for (const s of ['not_started', 'in_progress', 'closed']) expect(enKeys.has(`visit.status.${s}`)).toBe(true);
        for (const s of ['open', 'all']) expect(enKeys.has(`visit.filter.${s}`)).toBe(true);
        for (const s of ['pickup', 'return']) expect(enKeys.has(`inspection.${s}`)).toBe(true);
        for (const s of ['pickupWait', 'transitFirst', 'approvalWait', 'vendorWork', 'betweenVendors', 'returnTransit']) expect(enKeys.has(`stage.${s}`)).toBe(true);
        for (const v of DEFAULT_LISTS.damageLocations) expect(enKeys.has(`lists.location.${v.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '')}`)).toBe(true);
    });
    test('lookup, params, fallback, RTL', () => {
        expect(translate('en', 'visit.stopsCount', { n: 3 })).toBe('3 vendor stops');
        expect(translate('ar', 'vendor.arrived')).toBe('وصلت');
        expect(translate('ar-SA', 'common.save')).toBe('حفظ');
        expect(translate('fr', 'common.save')).toBe('Save'); // unknown locale falls back to English
        expect(translate('en', 'no.such.key')).toBe('no.such.key');
        expect(translateValue('ar', 'damage.condition', 'Pre-existing')).toBe('سابق');
        expect(translateValue('ar', 'lists.location', 'Something custom')).toBe('Something custom');
        expect(isRtlLocale('ar')).toBe(true);
        expect(isRtlLocale('en')).toBe(false);
    });
});
