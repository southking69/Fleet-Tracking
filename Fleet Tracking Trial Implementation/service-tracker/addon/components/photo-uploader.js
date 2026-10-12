import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { shrinkImage, isImageFile } from '../utils/image';

/**
 * <PhotoUploader
 *    @photos={{record.photos}}
 *    @subject={{hash type="damage_record" uuid=record.uuid collection="photos"}}
 *    @label="Add photos" @hint="..."
 *    @onChange={{this.photosChanged}} />
 *
 * Accepts click-to-choose, drag & drop, clipboard paste (Ctrl+V while the drop zone is focused) and a
 * camera button (capture=environment on phones). Every image is resized client-side to max 1000px and
 * re-encoded as JPEG quality 0.72 before upload. `onChange` fires after each upload/removal so the
 * parent can reload the record.
 */
export default class PhotoUploaderComponent extends Component {
    @service('service-tracker-api') api;
    @service notifications;
    @service intl;

    @tracked busy = false;
    @tracked over = false;
    @tracked pending = 0;

    get photos() {
        return Array.isArray(this.args.photos) ? this.args.photos : [];
    }

    get disabled() {
        return !this.args.subject?.uuid || this.busy;
    }

    async ingest(fileList) {
        const files = [...fileList].filter(isImageFile);
        if (!files.length || !this.args.subject?.uuid) {
            return;
        }
        this.busy = true;
        this.pending = files.length;
        try {
            const resized = [];
            for (const f of files) {
                try {
                    resized.push(await shrinkImage(f));
                } catch (e) {
                    this.notifications.warning(this.intl.t('service-tracker.photos.unreadable', { name: f.name }));
                }
                this.pending -= 1;
            }
            if (resized.length) {
                const uploaded = await this.api.uploadPhotos(this.args.subject, resized);
                this.args.onChange?.(uploaded);
            }
        } catch (e) {
            this.notifications.error(e.message || this.intl.t('service-tracker.photos.upload-failed'));
        } finally {
            this.busy = false;
            this.pending = 0;
        }
    }

    @action choose(event) {
        this.ingest(event.target.files);
        event.target.value = '';
    }

    @action openPicker(inputId) {
        document.getElementById(inputId)?.click();
    }

    @action dragOver(event) {
        event.preventDefault();
        this.over = true;
    }

    @action dragLeave() {
        this.over = false;
    }

    @action drop(event) {
        event.preventDefault();
        this.over = false;
        this.ingest(event.dataTransfer?.files ?? []);
    }

    @action paste(event) {
        const files = [...(event.clipboardData?.files ?? [])];
        if (files.length) {
            event.preventDefault();
            this.ingest(files);
        }
    }

    @action async remove(photo) {
        if (!window.confirm(this.intl.t('service-tracker.photos.confirm-remove'))) {
            return;
        }
        try {
            await this.api.deletePhoto(photo.uuid);
            this.args.onChange?.();
        } catch (e) {
            this.notifications.error(e.message);
        }
    }
}
