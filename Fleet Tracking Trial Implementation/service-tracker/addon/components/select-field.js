import Component from '@glimmer/component';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

/**
 * <SelectField @value={{x}} @options={{list}} @onChange={{fn}} @blank="All" />
 * Options may be strings or { value, label }. A value that is not in the list is kept visible.
 */
export default class SelectFieldComponent extends Component {
    @service intl;

    get items() {
        const raw = (this.args.options ?? []).map((o) => (o !== null && typeof o === 'object' ? { value: String(o.value), label: o.label ?? String(o.value) } : { value: String(o), label: String(o) }));
        const cur = this.args.value;
        if (cur !== undefined && cur !== null && cur !== '' && !raw.some((o) => o.value === String(cur))) {
            raw.push({ value: String(cur), label: String(cur) });
        }
        const sel = cur === undefined || cur === null ? '' : String(cur);
        return raw.map((o) => ({ ...o, selected: o.value === sel }));
    }

    get showBlank() {
        return this.args.blank !== false;
    }

    get blankLabel() {
        return typeof this.args.blank === 'string' ? this.args.blank : '—';
    }

    get blankSelected() {
        return this.args.value === undefined || this.args.value === null || this.args.value === '';
    }

    @action change(event) {
        const v = event.target.value;
        this.args.onChange?.(v === '' ? null : v);
    }
}
