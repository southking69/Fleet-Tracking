/**
 * KeyValueStorage backed by the app's existing MMKV instance (src/hooks/use-storage).
 * Synchronous, so a queue write is durable before the promise resolves.
 */
import { storage } from '../hooks/use-storage';
import type { KeyValueStorage } from './offlineQueue';

export const mmkvQueueStorage: KeyValueStorage = {
    getItem: (key) => storage.getString(key) ?? null,
    setItem: (key, value) => {
        storage.set(key, value);
    },
    removeItem: (key) => {
        storage.remove(key);
    },
};

/** AsyncStorage alternative, if a fork prefers it (not used by default). */
export const asyncStorageAdapter = (AsyncStorage: { getItem(k: string): Promise<string | null>; setItem(k: string, v: string): Promise<void>; removeItem(k: string): Promise<void> }): KeyValueStorage => ({
    getItem: (k) => AsyncStorage.getItem(k),
    setItem: (k, v) => AsyncStorage.setItem(k, v),
    removeItem: (k) => AsyncStorage.removeItem(k),
});
