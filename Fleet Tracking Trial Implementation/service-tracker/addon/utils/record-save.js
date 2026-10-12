/** Save an Ember Data record, surfacing server errors as console notifications. Resolves true/false. */
export async function saveRecord(record, notifications) {
    try {
        await record.save();
        return true;
    } catch (error) {
        if (typeof notifications?.serverError === 'function') {
            notifications.serverError(error);
        } else {
            notifications?.error(error?.message ?? String(error));
        }
        return false;
    }
}
