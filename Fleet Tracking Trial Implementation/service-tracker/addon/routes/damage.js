import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class DamageRoute extends Route {
    @service store;

    queryParams = {
        q: { refreshModel: true },
        severity: { refreshModel: true },
        condition: { refreshModel: true },
        stage: { refreshModel: true },
    };

    model({ q, severity, condition, stage }) {
        return this.store.query('damage-record', {
            plate: q || undefined,
            severity: severity || undefined,
            condition: condition || undefined,
            stage: stage || undefined,
            limit: 200,
            sort: '-occurred_at',
        });
    }
}
