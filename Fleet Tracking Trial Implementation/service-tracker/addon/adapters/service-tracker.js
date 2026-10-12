import ApplicationAdapter from '@fleetbase/ember-core/adapters/application';
import { RESOURCES, NAMESPACE } from '../utils/resources';

/**
 * Base adapter: every Service Tracker model talks to /int/v1/service-tracker/<resource>.
 * (`int/v1` is the namespace configured on the console's application adapter.)
 */
export default class ServiceTrackerAdapter extends ApplicationAdapter {
    pathForType(modelName) {
        const r = RESOURCES[modelName];
        return `${NAMESPACE}/${r ? r.path : modelName}`;
    }
}
