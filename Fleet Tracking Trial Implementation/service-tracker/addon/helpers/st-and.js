import { helper } from '@ember/component/helper';

export default helper((args) => (args.every((a) => a) ? args[args.length - 1] : false));
