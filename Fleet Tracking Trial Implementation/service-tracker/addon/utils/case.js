const camel = (k) => k.replace(/_([a-z0-9])/g, (_, c) => c.toUpperCase());

/** Deep snake_case -> camelCase key conversion for plain API payloads (non Ember Data endpoints). */
export function camelizeKeys(value) {
    if (Array.isArray(value)) {
        return value.map(camelizeKeys);
    }
    if (value && typeof value === 'object' && Object.getPrototypeOf(value) === Object.prototype) {
        return Object.keys(value).reduce((out, k) => {
            out[camel(k)] = camelizeKeys(value[k]);
            return out;
        }, {});
    }
    return value;
}
