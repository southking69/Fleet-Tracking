import Component from '@glimmer/component';
import { action } from '@ember/object';
import { PERIOD_PRESETS, resolvePeriod } from '../utils/periods';

/**
 * <PeriodPicker @from @to @onChange={{fn}} /> — presets (Today, Last 7 days, Last 30 days, This month, Last month)
 * plus manual from/to dates. `onChange({ from, to })`.
 */
export default class PeriodPickerComponent extends Component {
    presets = PERIOD_PRESETS;

    get active() {
        return PERIOD_PRESETS.find((p) => {
            const [f, t] = resolvePeriod(p);
            return f === this.args.from && t === this.args.to;
        });
    }

    get presetItems() {
        return PERIOD_PRESETS.map((p) => ({ key: p, active: p === this.active }));
    }

    @action preset(key) {
        const [from, to] = resolvePeriod(key);
        this.args.onChange?.({ from, to });
    }

    @action setFrom(event) {
        this.args.onChange?.({ from: event.target.value, to: this.args.to ?? '' });
    }

    @action setTo(event) {
        this.args.onChange?.({ from: this.args.from ?? '', to: event.target.value });
    }

    @action clear() {
        this.args.onChange?.({ from: '', to: '' });
    }
}
