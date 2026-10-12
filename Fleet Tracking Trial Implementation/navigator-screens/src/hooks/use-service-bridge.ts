import { useContext, useMemo } from 'react';
import ServiceVisitContext from '../contexts/ServiceVisitContext';
import type { Visit } from '../services/visitModel';
import { toMinorUnits } from '../services/visitModel';

/**
 * Thin bridge so the stock IssueForm / FuelReportForm can hook into the Service Tracker with 3-4 lines
 * (see PATCHES.md). Safe when the provider is absent: `available` is false and everything is a no-op.
 */
export default function useServiceBridge() {
    const ctx = useContext(ServiceVisitContext);
    return useMemo(() => {
        if (!ctx) return { available: false as const, openVisits: [] as Visit[], activeVisit: undefined as Visit | undefined, logFuelAsPettyCash: async (_amount: string, _currency: string, _desc?: string) => {} };
        const openVisits = ctx.visits.filter((v) => !v.returned_at);
        const activeVisit = openVisits[0];
        return {
            available: true as const,
            openVisits,
            activeVisit,
            /** Records a Fuel petty-cash entry (queued offline-safe) for the open visit, if any. */
            logFuelAsPettyCash: async (amount: string, currency: string, desc?: string) => {
                const minor = toMinorUnits(amount);
                if (minor == null || minor <= 0) return;
                await ctx.addPettyCash({ category: 'Fuel', amount: minor, currency: currency || 'SAR', description: desc, plate: activeVisit?.plate }, [], activeVisit?.id ?? null);
            },
        };
    }, [ctx]);
}
