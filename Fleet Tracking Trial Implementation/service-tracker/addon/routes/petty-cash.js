import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class PettyCashRoute extends Route {
    @service store;

    queryParams = {
        from: { refreshModel: true },
        to: { refreshModel: true },
        q: { refreshModel: true },
        paid_by: { refreshModel: true },
    };

    model({ from, to, q, paid_by }) {
        return this.store.query('petty-cash-entry', {
            from: from || undefined,
            to: to || undefined,
            plate: q || undefined,
            paid_by: paid_by || undefined,
            limit: 200,
            sort: '-entry_date',
        });
    }
}
