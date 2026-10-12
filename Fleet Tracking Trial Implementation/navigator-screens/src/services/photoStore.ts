/**
 * Durable local copies of photos waiting for upload. Camera/resizer temp files can be purged by the OS
 * while the device is offline for days, so each captured photo is resized (1000 px / q0.72) and then
 * copied into the app's documents directory. The copy is deleted once the queue has delivered it.
 */
import RNFS from 'react-native-fs';
import { resizeForUpload } from './imageResize';

const DIR = `${RNFS.DocumentDirectoryPath}/service-tracker-uploads`;

const stripScheme = (uri: string) => uri.replace(/^file:\/\//, '');
const withScheme = (path: string) => (path.startsWith('file://') ? path : `file://${path}`);

export async function persistPhoto(uri: string): Promise<string> {
    await RNFS.mkdir(DIR);
    const resized = await resizeForUpload(uri);
    const dest = `${DIR}/${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}.jpg`;
    await RNFS.copyFile(stripScheme(resized), dest);
    if (resized !== uri) {
        // the resizer's temp output is no longer needed
        RNFS.unlink(stripScheme(resized)).catch(() => {});
    }
    return withScheme(dest);
}

export async function removePhoto(uri: string): Promise<void> {
    if (!uri || !uri.includes('service-tracker-uploads')) return;
    try {
        await RNFS.unlink(stripScheme(uri));
    } catch {
        /* already gone */
    }
}
