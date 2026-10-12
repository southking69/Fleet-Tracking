import { isoDate } from './format';

export const PERIOD_PRESETS = ['today', 'last7', 'last30', 'thisMonth', 'lastMonth'];

/** Resolve a preset to an inclusive [from, to] pair of YYYY-MM-DD strings. */
export function resolvePeriod(preset, now = new Date()) {
    const back = (n) => {
        const x = new Date(now);
        x.setDate(x.getDate() - n);
        return isoDate(x);
    };
    switch (preset) {
        case 'today':
            return [isoDate(now), isoDate(now)];
        case 'last7':
            return [back(6), isoDate(now)];
        case 'last30':
            return [back(29), isoDate(now)];
        case 'thisMonth':
            return [isoDate(new Date(now.getFullYear(), now.getMonth(), 1)), isoDate(now)];
        case 'lastMonth':
            return [isoDate(new Date(now.getFullYear(), now.getMonth() - 1, 1)), isoDate(new Date(now.getFullYear(), now.getMonth(), 0))];
        default:
            return ['', ''];
    }
}
