import Component from '@glimmer/component';
import { action } from '@ember/object';
import { isoToLocalInput, localInputToIso } from '../utils/format';

/**
 * <DatetimeNowField @value={{iso}} @onChange={{fn}} /> — datetime-local input bound to an ISO UTC string
 * with a "Now" button that stamps the current time.
 */
export default class DatetimeNowFieldComponent extends Component {
    get localValue() {
        return isoToLocalInput(this.args.value);
    }

    @action change(event) {
        this.args.onChange?.(localInputToIso(event.target.value));
    }

    @action now() {
        this.args.onChange?.(new Date().toISOString());
    }
}
