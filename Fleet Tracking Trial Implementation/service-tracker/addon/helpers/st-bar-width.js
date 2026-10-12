import { helper } from '@ember/component/helper';

/** CSS width for a bar of `value` against the largest of `max` (presentation only). */
export default helper(([value, max]) => {
    const v = Number(value) || 0;
    const m = Number(max) || 0.01;
    return v > 0 ? `width:${Math.max(2, (v / m) * 100)}%` : 'width:0%';
});
