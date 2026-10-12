/**
 * Display-only formatting helpers. No business calculation lives here: every metric shown in the console
 * (turnaround stages, owner, met/missed, due status, ...) is supplied by the server `calc` payload.
 */

export function isNil(v) {
    return v === null || v === undefined || v === '' || (typeof v === 'number' && isNaN(v));
}

/** 1 decimal hour value, en dash when missing. */
export function fmtHours(v) {
    return isNil(v) ? '–' : (Math.round(Number(v) * 10) / 10).toFixed(1);
}

export function fmtInt(v) {
    return isNil(v) ? '–' : Math.round(Number(v)).toLocaleString('en-US');
}

/** Money is stored in minor units (cents). */
export function fmtMoney(minor) {
    if (isNil(minor)) {
        return '–';
    }
    return (Number(minor) / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export function toMinor(major) {
    if (isNil(major)) {
        return null;
    }
    return Math.round(Number(major) * 100);
}

export function toMajor(minor) {
    return isNil(minor) ? '' : Number(minor) / 100;
}

const pad = (n) => String(n).padStart(2, '0');

export function fmtDate(v) {
    if (!v) {
        return '';
    }
    const d = new Date(String(v).length === 10 ? `${v}T00:00:00` : v);
    if (isNaN(d.getTime())) {
        return '';
    }
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return `${pad(d.getDate())}-${months[d.getMonth()]}-${d.getFullYear()}`;
}

export function fmtDateTime(v) {
    if (!v) {
        return '';
    }
    const d = new Date(v);
    if (isNaN(d.getTime())) {
        return '';
    }
    return `${fmtDate(d)} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** ISO (UTC) -> value for <input type="datetime-local"> in the viewer's timezone. */
export function isoToLocalInput(iso) {
    if (!iso) {
        return '';
    }
    const d = new Date(iso);
    if (isNaN(d.getTime())) {
        return '';
    }
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** <input type="datetime-local"> value -> ISO UTC string. */
export function localInputToIso(local) {
    if (!local) {
        return null;
    }
    const d = new Date(local);
    return isNaN(d.getTime()) ? null : d.toISOString();
}

export function isoDate(d) {
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

export function todayIso() {
    return isoDate(new Date());
}

/** Normalise a plate for matching, e.g. "ABC 1234" -> "ABC1234". */
export function plateKey(s) {
    return String(s || '')
        .toUpperCase()
        .replace(/[\s\-_.]/g, '');
}
