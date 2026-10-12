/**
 * Photo preparation for upload: longest side <= 1000 px, JPEG quality 0.72 (SPEC: client-side already resized).
 * Reuses @bam.tech/react-native-image-resizer (already a dependency of navigator-app, see src/utils/index.js resizePhoto).
 */
import { Image } from 'react-native';
import ImageResizer from '@bam.tech/react-native-image-resizer';
import { PHOTO_MAX_PX, PHOTO_JPEG_QUALITY, fitWithin } from './visitModel';

function getSize(uri: string): Promise<{ width: number; height: number } | null> {
    return new Promise((resolve) => Image.getSize(uri, (width, height) => resolve({ width, height }), () => resolve(null)));
}

/** Returns the uri of a resized JPEG. If the size cannot be read the original uri is returned (never lose a photo). */
export async function resizeForUpload(uri: string): Promise<string> {
    const size = await getSize(uri);
    const target = size ? fitWithin(size.width, size.height) : { width: PHOTO_MAX_PX, height: PHOTO_MAX_PX };
    try {
        const out = await ImageResizer.createResizedImage(uri, target.width, target.height, 'JPEG', PHOTO_JPEG_QUALITY, 0, undefined, false, { mode: 'contain', onlyScaleDown: true });
        return out.uri;
    } catch (e) {
        console.warn('resizeForUpload failed, using original', e);
        return uri;
    }
}
