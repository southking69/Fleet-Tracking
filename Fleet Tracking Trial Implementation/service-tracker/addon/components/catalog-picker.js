import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

/**
 * <CatalogPicker @value="Oil change" @items={{catalog}} @onPick={{fn}} @onCreate={{fn}} />
 * Type-ahead over the service catalog (all typed words must match name or category, max 15 results).
 * Offers "+ Add as new service" when the typed name is not in the catalog.
 */
export default class CatalogPickerComponent extends Component {
    @tracked query = null;
    @tracked open = false;
    @tracked highlighted = 0;

    get text() {
        return this.query ?? this.args.value ?? '';
    }

    get results() {
        const words = this.text.trim().toLowerCase().split(/\s+/).filter(Boolean);
        return (this.args.items ?? [])
            .filter((c) => words.every((w) => `${c.name} ${c.category ?? ''}`.toLowerCase().includes(w)))
            .slice(0, 15)
            .map((c) => ({ item: c, name: c.name, meta: [c.category, c.lane_name].filter(Boolean).join(' · ') }));
    }

    get canCreate() {
        const q = this.text.trim().toLowerCase();
        return !!q && !(this.args.items ?? []).some((c) => (c.name ?? '').toLowerCase() === q);
    }

    @action focus(event) {
        event.target.select();
        this.open = true;
    }

    @action input(event) {
        this.query = event.target.value;
        this.open = true;
        this.highlighted = 0;
    }

    @action blur() {
        // mousedown handlers on the options run before blur, so closing here is safe
        this.open = false;
        this.query = null;
    }

    @action pick(row, event) {
        event?.preventDefault();
        this.open = false;
        this.query = null;
        this.args.onPick?.(row.item);
    }

    @action create(event) {
        event?.preventDefault();
        const name = this.text.trim();
        this.open = false;
        this.query = null;
        this.args.onCreate?.(name);
    }

    @action keydown(event) {
        if (event.key === 'Escape') {
            this.open = false;
            event.target.blur();
        } else if (event.key === 'ArrowDown') {
            event.preventDefault();
            this.highlighted = Math.min(this.highlighted + 1, this.results.length - 1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            this.highlighted = Math.max(this.highlighted - 1, 0);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            const row = this.results[this.highlighted];
            if (row) {
                this.pick(row);
            } else if (this.canCreate) {
                this.create();
            }
        }
    }
}
