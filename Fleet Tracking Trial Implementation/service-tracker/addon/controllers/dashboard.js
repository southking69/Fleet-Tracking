import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { STAGE_LABEL_KEYS, STAGE_OWNERS } from '../utils/stages';
import { todayIso } from '../utils/format';

/** Renders the server `dashboard` document; no metric is computed here. */
export default class DashboardController extends Controller {
    @service intl;
    @service notifications;
    @service('service-tracker-api') api;

    queryParams = ['from', 'to'];
    @tracked from = '';
    @tracked to = '';

    get settings() {
        return this.api.settings ?? {};
    }

    get kpis() {
        return this.model?.kpis ?? {};
    }

    /**
     * The dashboard document is the server's (snake_case keys, camelised by the api service):
     * kpis{visits,closed,inProgress,jobs,stops,pettyCashTotalMinor,avgTurnaroundHours,pctWithinTarget,openOver24h},
     * stages[{name,short,owner,avgHours,sharePct}], byOwner[{owner,closedVisits}], byLane[{lane,targetHours,closedVisits,avgTurnaroundHours,pctWithinTarget}],
     * byVendor[{vendor,stops,jobsDone,avgHoursPerStop,avgApprovalWaitHours,avgNetWorkHours}], jobsByCategory[{category,jobs}],
     * damageInspections{...}, dueCompliance{...}.
     */
    get stageRows() {
        const stages = this.model?.stages ?? [];
        return STAGE_LABEL_KEYS.map((key, i) => ({
            label: this.intl.t(key),
            owner: stages[i]?.owner ?? STAGE_OWNERS[i],
            avg: stages[i]?.avgHours,
            share: stages[i]?.sharePct ?? 0,
        }));
    }

    get ownerRows() {
        return (this.model?.byOwner ?? []).map((o) => ({ owner: o.owner, closed: o.closedVisits }));
    }

    get laneRows() {
        return (this.model?.byLane ?? []).map((l) => ({ name: l.lane, target: l.targetHours, closed: l.closedVisits, avg: l.avgTurnaroundHours, pctWithin: l.pctWithinTarget }));
    }

    get vendorRows() {
        return (this.model?.byVendor ?? []).map((v) => ({ name: v.vendor, stops: v.stops, jobsDone: v.jobsDone, avgPerStop: v.avgHoursPerStop, avgApproval: v.avgApprovalWaitHours, avgNet: v.avgNetWorkHours }));
    }

    get categoryRows() {
        return this.model?.jobsByCategory ?? [];
    }

    get damageRows() {
        const d = this.model?.damageInspections ?? {};
        return [
            ['service-tracker.dashboard.damage.records', d.damageRecords],
            ['service-tracker.dashboard.damage.new', d.damageNew],
            ['service-tracker.dashboard.damage.no-pickup-inspection', d.closedWithoutPickupInspection],
            ['service-tracker.dashboard.damage.no-return-inspection', d.closedWithoutReturnInspection],
            ['service-tracker.dashboard.damage.no-pickup-photos', d.closedWithoutPickupPhotos],
            ['service-tracker.dashboard.damage.no-return-photos', d.closedWithoutReturnPhotos],
            ['service-tracker.dashboard.damage.data-problems', d.closedWithDataProblems],
        ].map(([key, value]) => ({ label: this.intl.t(key), value }));
    }

    get complianceRows() {
        const c = this.model?.dueCompliance ?? {};
        const none = (v) => v === null || v === undefined;
        const pct = none(c.pctOverdue) ? '–' : `${Math.round(c.pctOverdue)}%`;
        return [
            ['service-tracker.dashboard.compliance.comparable', c.jobsWithPreviousDue],
            ['service-tracker.dashboard.compliance.overdue', c.jobsOverdue],
            ['service-tracker.dashboard.compliance.pct', pct],
            ['service-tracker.dashboard.compliance.avg-km', none(c.avgKmOverdue) ? '–' : Number(c.avgKmOverdue).toLocaleString('en-US')],
            ['service-tracker.dashboard.compliance.worst-km', none(c.worstKmOverdue) ? '–' : Number(c.worstKmOverdue).toLocaleString('en-US')],
            ['service-tracker.dashboard.compliance.visits-overdue', c.visitsWithOverdueJob],
            ['service-tracker.dashboard.compliance.missing-odo', c.jobsMissingOdometer],
        ].map(([key, value]) => ({ label: this.intl.t(key), value }));
    }

    @action setPeriod({ from, to }) {
        this.from = from;
        this.to = to;
    }

    @action async download() {
        try {
            const s = this.settings;
            const blob = await this.api.exportPeriod({ from: this.from, to: this.to, timing: s.excelTiming, petty: s.excelPetty, vehicles: s.excelVehicles });
            this.api.saveBlob(blob, `Service_Report_${this.from || 'start'}_to_${this.to || todayIso()}.xlsx`);
        } catch (e) {
            this.notifications.error(e.message);
        }
    }
}
