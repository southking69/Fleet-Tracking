import Component from '@glimmer/component';

/**
 * Live summary sidebar of a visit. Everything is rendered from the server `calc` document:
 * status, met/missed, total/open, target+lane, six stages, biggest delay + owner, per-vendor hours, data check.
 */
export default class VisitSummaryComponent extends Component {
    get calc() {
        return this.args.calc ?? {};
    }

    get overdueLabelKey() {
        return this.calc.overdueN > 1 ? 'service-tracker.summary.overdue-n-plural' : 'service-tracker.summary.overdue-n';
    }

    get hasMet() {
        return this.calc.met !== null && this.calc.met !== undefined;
    }

    /** Normalise perVendor (array of numbers, array of {name,hours} or an object map) to [{ name, hours }]. */
    get vendors() {
        const pv = this.calc.perVendor;
        let rows = [];
        if (Array.isArray(pv)) {
            rows = pv.map((x, i) => (x !== null && typeof x === 'object' ? { name: x.name ?? x.vendor ?? x.vendorName, hours: x.hours ?? x.total } : { name: this.calc.vendorNames?.[i], hours: x }));
        } else if (pv && typeof pv === 'object') {
            rows = Object.entries(pv).map(([name, hours]) => ({ name, hours }));
        }
        return rows.filter((r) => r.name && Number(r.hours) > 0);
    }

    get checkTone() {
        return this.calc.check === 'OK' ? 'ok' : 'warn';
    }
}
