const useFleetbase = (): { fleetbase: any; adapter: any; error: Error | null; hasFleetbaseConfig: () => boolean } => ({ fleetbase: null, adapter: null, error: null, hasFleetbaseConfig: () => true });
export default useFleetbase;
