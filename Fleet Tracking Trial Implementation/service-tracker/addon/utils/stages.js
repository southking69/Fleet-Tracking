/** Static labels for the six turnaround stages (order matches the server calc `stages` array). */
export const STAGE_KEYS = ['pickupWait', 'transitFirst', 'approvalWait', 'vendorWork', 'betweenVendors', 'returnTransit'];
export const STAGE_LABEL_KEYS = STAGE_KEYS.map((k) => `service-tracker.stages.${k}`);
export const STAGE_OWNERS = ['Our team', 'Our team', 'Splendor', 'Vendor', 'Our team', 'Our team'];
