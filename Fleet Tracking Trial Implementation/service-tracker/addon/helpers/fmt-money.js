import { helper } from '@ember/component/helper';
import { fmtMoney } from '../utils/format';

export default helper(([v]) => fmtMoney(v));
