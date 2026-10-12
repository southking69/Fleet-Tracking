import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { STAGE_LABEL_KEYS, STAGE_OWNERS } from '../utils/stages';

/**
 * <StageBar @stages={{calc.stages}} @big={{calc.big}} /> — horizontal bar chart of the six turnaround stages.
 * Values are the server's; only the bar widths (relative to the longest stage) are computed here.
 */
export default class StageBarComponent extends Component {
    @service intl;

    get max() {
        return Math.max(0.01, ...(this.args.stages ?? []).map((x) => Number(x) || 0));
    }

    get rows() {
        const stages = this.args.stages ?? [];
        return STAGE_LABEL_KEYS.map((key, i) => ({
            label: this.intl.t(key),
            owner: STAGE_OWNERS[i],
            value: stages[i],
            big: i === this.args.big,
        }));
    }
}
