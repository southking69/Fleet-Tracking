// Shape of the MMKV-backed `storage` exported by navigator-app/src/hooks/use-storage.ts
export const storage: { getString(k: string): string | undefined; set(k: string, v: string | number | boolean): void; remove(k: string): boolean | void; getNumber(k: string): number | undefined } = null as any;
