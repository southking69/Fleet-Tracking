import { helper } from '@ember/component/helper';
import { fmtInt } from '../utils/format';

export default helper(([v]) => fmtInt(v));
