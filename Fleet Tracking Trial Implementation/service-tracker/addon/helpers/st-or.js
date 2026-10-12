import { helper } from '@ember/component/helper';

export default helper((args) => args.find((a) => a) ?? args[args.length - 1]);
