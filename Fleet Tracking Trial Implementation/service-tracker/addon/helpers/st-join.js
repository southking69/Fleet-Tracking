import { helper } from '@ember/component/helper';

export default helper(([list, sep]) => (Array.isArray(list) ? list.filter(Boolean).join(sep ?? ', ') : ''));
