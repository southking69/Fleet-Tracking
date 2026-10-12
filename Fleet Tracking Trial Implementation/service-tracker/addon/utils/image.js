export const MAX_EDGE = 1000;
export const JPEG_QUALITY = 0.72;

/**
 * Resize an image File/Blob so its longest edge is at most 1000px and re-encode as JPEG quality 0.72.
 * Returns a Promise<File> (image/jpeg).
 */
export function shrinkImage(file) {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('Could not read image'));
        };
        img.onload = () => {
            URL.revokeObjectURL(url);
            const k = Math.min(1, MAX_EDGE / Math.max(img.width, img.height));
            const canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.round(img.width * k));
            canvas.height = Math.max(1, Math.round(img.height * k));
            canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
            canvas.toBlob(
                (blob) => {
                    if (!blob) {
                        return reject(new Error('Could not encode image'));
                    }
                    const base = (file.name || `photo-${Date.now()}`).replace(/\.[^.]+$/, '');
                    resolve(new File([blob], `${base}.jpg`, { type: 'image/jpeg' }));
                },
                'image/jpeg',
                JPEG_QUALITY
            );
        };
        img.src = url;
    });
}

export function isImageFile(f) {
    return /^image\//.test(f.type) || /\.(jpe?g|png|heic|webp|gif|bmp)$/i.test(f.name || '');
}
