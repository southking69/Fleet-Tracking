/**
 * Transport that sends queue items through the app's Fleetbase adapter
 * (useFleetbase().adapter, from @fleetbase/sdk) exactly like the other screens do
 * (`adapter.post(path, body, { headers })`).
 */
import { NetworkError, PermanentError, Transport, TransportRequest, TransportResponse } from './offlineQueue';

interface AdapterLike {
    get(path: string, query?: any, options?: any): Promise<any>;
    post(path: string, data?: any, options?: any): Promise<any>;
    put(path: string, data?: any, options?: any): Promise<any>;
    patch?(path: string, data?: any, options?: any): Promise<any>;
    delete(path: string, options?: any): Promise<any>;
}

/** Pulls an HTTP status out of whatever the SDK/axios/fetch layer threw. Undefined = no response (offline / timeout). */
export function statusOf(err: any): number | undefined {
    const s = err?.status ?? err?.response?.status ?? err?.statusCode;
    return typeof s === 'number' ? s : undefined;
}

export function createFleetbaseTransport(getAdapter: () => AdapterLike | null | undefined): Transport {
    return {
        async send(req: TransportRequest): Promise<TransportResponse> {
            const adapter = getAdapter();
            // No adapter yet (app still booting / logged out): behave like "offline" so nothing is lost.
            if (!adapter) throw new NetworkError('adapter not ready');
            try {
                let data: any;
                if (req.kind === 'photo') {
                    const p = req.photo!;
                    const form = new FormData();
                    Object.entries(p.fields).forEach(([k, v]) => form.append(k, v));
                    // React Native FormData file part: { uri, name, type }
                    form.append('photos[0]', { uri: p.uri, name: p.name, type: p.type } as any);
                    data = await adapter.post(req.path, form, { headers: { 'Content-Type': 'multipart/form-data' } });
                } else if (req.method === 'POST') {
                    data = await adapter.post(req.path, req.body);
                } else if (req.method === 'PUT') {
                    data = await adapter.put(req.path, req.body);
                } else if (req.method === 'PATCH') {
                    data = adapter.patch ? await adapter.patch(req.path, req.body) : await adapter.put(req.path, req.body);
                } else {
                    data = await adapter.delete(req.path);
                }
                return { status: 200, data };
            } catch (e: any) {
                const status = statusOf(e);
                if (status !== undefined) {
                    const err: any = new Error(e?.message || `HTTP ${status}`);
                    err.status = status;
                    err.response = e?.response;
                    throw err;
                }
                const msg = String(e?.message || e);
                // RN throws ENOENT-style errors when the local file is gone: that can never succeed.
                if (req.kind === 'photo' && /ENOENT|No such file|file.*not (found|exist)/i.test(msg)) throw new PermanentError(msg);
                throw new NetworkError(msg);
            }
        },
    };
}
