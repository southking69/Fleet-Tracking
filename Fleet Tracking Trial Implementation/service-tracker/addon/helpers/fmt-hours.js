import { helper } from '@ember/component/helper';
import { fmtHours } from '../utils/format';

export default helper(([v]) => fmtHours(v));
