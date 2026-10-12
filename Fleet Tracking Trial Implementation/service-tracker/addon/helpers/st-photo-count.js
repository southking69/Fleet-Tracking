import { helper } from '@ember/component/helper';

export default helper(([list]) => (Array.isArray(list) ? list.length : 0));
