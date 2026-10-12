import Component from '@glimmer/component';
import { inject as service } from '@ember/service';

const TONES = {
    closed: 'ok',
    'on time': 'ok',
    ok: 'ok',
    done: 'ok',
    yes: 'ok',
    'in progress': 'blue',
    overdue: 'bad',
    new: 'bad',
    major: 'bad',
    no: 'neutral',
    'not started': 'neutral',
    pending: 'neutral',
    'odometer missing': 'warn',
    'due soon': 'warn',
    moderate: 'warn',
};

const CLASSES = {
    ok: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-100',
    bad: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-100',
    warn: 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-100',
    blue: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-100',
    neutral: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
};

/**
 * <StatusBadge @status="Closed" /> or <StatusBadge @text="3 overdue" @tone="bad" />
 * Known server status strings are translated when a `service-tracker.status.<slug>` key exists.
 */
export default class StatusBadgeComponent extends Component {
    @service intl;

    get raw() {
        return this.args.text ?? this.args.status ?? '';
    }

    get tone() {
        return this.args.tone ?? TONES[String(this.raw).toLowerCase()] ?? 'neutral';
    }

    get classes() {
        return CLASSES[this.tone] ?? CLASSES.neutral;
    }

    get label() {
        if (this.args.text !== undefined) {
            return this.args.text;
        }
        const slug = String(this.raw).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
        const key = `service-tracker.status.${slug}`;
        return this.intl.exists(key) ? this.intl.t(key) : this.raw;
    }
}
