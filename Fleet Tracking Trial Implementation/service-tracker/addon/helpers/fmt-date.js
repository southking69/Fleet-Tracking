import { helper } from '@ember/component/helper';
import { fmtDate } from '../utils/format';

export default helper(([v]) => fmtDate(v) || '–');
