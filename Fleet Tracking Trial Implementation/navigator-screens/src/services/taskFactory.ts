/**
 * Server field names (service-tracker/server/src/Http/Requests): children reference their visit by `service_visit_uuid`
 * (the server accepts the uuid OR the public id there), catalog items by `catalog_item_uuid`, photos by
 * `subject_type` (visit|damage|petty_cash) + `subject_id` (+ `stage`, `angle`, `parent_client_uuid`), and every write carries `client_uuid`.
 *
 * Builds offline-queue tasks for every driver write. Pure and testable; the context just
 * enqueues what these return. Endpoints follow SPEC.md (driver API, prefix v1/service-tracker
 * is added by the Fleetbase adapter's namespace, so paths here are `service-tracker/...`).
 */
import { ref, RecordTaskInput, PhotoTaskInput, uuidv4 } from './offlineQueue';
import { StopAction } from './visitModel';

export const NS = 'service-tracker';
export const UPLOADS = `${NS}/uploads`;

/** Reference to a visit/stop/job that may exist only on this device yet. */
const idRef = (id: string, isLocal: boolean) => (isLocal ? ref(id) : id);

export interface Target {
    id: string;
    /** true when `id` is a client_uuid of a not-yet-delivered record */
    local: boolean;
}

export function createVisitTask(clientUuid: string, body: Record<string, any>): RecordTaskInput {
    return { kind: 'record', clientUuid, method: 'POST', path: `${NS}/visits`, body, label: 'visit' };
}

export function updateVisitTask(visit: Target, body: Record<string, any>, clientUuid = uuidv4()): RecordTaskInput {
    return { kind: 'record', clientUuid, method: 'PUT', path: `${NS}/visits/${idRef(visit.id, visit.local)}`, body, label: 'visit-update' };
}

export function createStopTask(visit: Target, clientUuid: string, body: Record<string, any>): RecordTaskInput {
    return { kind: 'record', clientUuid, method: 'POST', path: `${NS}/visits/${idRef(visit.id, visit.local)}/stops`, body, label: 'stop' };
}

/** Arrived / Approval requested / Approval received / Left. `at` is stamped at the moment of the tap. */
export function stopStampTask(visit: Target, stop: Target, action: StopAction, atIso: string, clientUuid = uuidv4()): RecordTaskInput {
    return {
        kind: 'record',
        clientUuid,
        method: 'POST',
        path: `${NS}/visits/${idRef(visit.id, visit.local)}/stops/${idRef(stop.id, stop.local)}/${action}`,
        body: { at: atIso },
        label: `stop-${action}`,
    };
}

export function createJobTask(visit: Target, clientUuid: string, body: Record<string, any>): RecordTaskInput {
    return { kind: 'record', clientUuid, method: 'POST', path: `${NS}/visits/${idRef(visit.id, visit.local)}/jobs`, body, label: 'job' };
}

export function updateJobTask(visit: Target, job: Target, body: Record<string, any>, clientUuid = uuidv4()): RecordTaskInput {
    return { kind: 'record', clientUuid, method: 'PUT', path: `${NS}/service-jobs/${idRef(job.id, job.local)}`, body: { ...body, service_visit_uuid: idRef(visit.id, visit.local) }, label: 'job-update' };
}

export function createDamageTask(clientUuid: string, visit: Target, body: Record<string, any>): RecordTaskInput {
    return { kind: 'record', clientUuid, method: 'POST', path: `${NS}/damage-records`, body: { ...body, service_visit_uuid: idRef(visit.id, visit.local) }, label: 'damage' };
}

export function createPettyCashTask(clientUuid: string, visit: Target | null, body: Record<string, any>): RecordTaskInput {
    return { kind: 'record', clientUuid, method: 'POST', path: `${NS}/petty-cash`, body: { ...body, service_visit_uuid: visit ? idRef(visit.id, visit.local) : null }, label: 'petty-cash' };
}

export interface PhotoSubject {
    /** The record the photo belongs to: local (queued) -> waits for delivery; otherwise a server id. */
    parent: Target;
    subjectType: 'visit' | 'damage' | 'petty_cash';
    /** visit photos: which inspection */
    stage?: 'pickup' | 'return';
    angle?: string;
}

export function photoTask(subject: PhotoSubject, fileUri: string, clientUuid = uuidv4()): PhotoTaskInput {
    const fields: Record<string, string> = { subject_type: subject.subjectType };
    if (subject.stage) fields.stage = subject.stage;
    if (subject.angle) fields.angle = subject.angle;
    return { kind: 'photo', clientUuid, ...(subject.parent.local ? { parentClientUuid: subject.parent.id } : { parentServerId: subject.parent.id }), path: UPLOADS, fileUri, fileName: `${clientUuid}.jpg`, mime: 'image/jpeg', fields, label: `photo-${subject.subjectType}` };
}
