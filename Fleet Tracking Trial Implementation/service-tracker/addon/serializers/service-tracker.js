import ApplicationSerializer from '@fleetbase/ember-core/serializers/application';
import { RESOURCES } from '../utils/resources';

/**
 * Maps Ember Data model names to the JSON root keys used by the Service Tracker REST API,
 * e.g. { "visits": [...] } / { "visit": {...} }, and keeps the server `calc` payload untouched.
 */
export default class ServiceTrackerSerializer extends ApplicationSerializer {
    payloadKeyFromModelName(modelName) {
        const r = RESOURCES[modelName];
        return r ? r.singular : super.payloadKeyFromModelName(modelName);
    }

    modelNameFromPayloadKey(key) {
        for (const [modelName, r] of Object.entries(RESOURCES)) {
            if (key === r.singular || key === r.plural) {
                return modelName;
            }
        }
        return super.modelNameFromPayloadKey(key);
    }

    /** `calc` is a server-computed document whose keys are camelCase; never rewrite them. */
    keyForAttribute(attr) {
        return attr === 'calc' ? 'calc' : super.keyForAttribute(attr);
    }
}
