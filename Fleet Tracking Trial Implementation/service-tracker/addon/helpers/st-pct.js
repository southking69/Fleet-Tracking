import { helper } from '@ember/component/helper';

/** Format an already-computed percentage from the server (0-100) or en dash. */
export default helper(([v]) => (v === null || v === undefined || v === '' ? '–' : `${Math.round(Number(v))}%`));
