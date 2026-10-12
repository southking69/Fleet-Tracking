import { helper } from '@ember/component/helper';
import { fmtDateTime } from '../utils/format';

export default helper(([v]) => fmtDateTime(v) || '–');
