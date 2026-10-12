import React, { useCallback, useMemo, useState } from 'react';
import { Pressable, ScrollView, TextInput } from 'react-native';
import { useNavigation, useRoute } from '@react-navigation/native';
import { YStack, XStack, Text, useTheme } from 'tamagui';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useServiceVisits } from '../contexts/ServiceVisitContext';
import { useServiceT } from '../translations';
import { Card, ChipSelect, EmptyState, FieldLabel, SaveBar, SyncBanner } from '../components/ServiceUi';
import { CatalogItem, JobStatus } from '../services/visitModel';

const JOB_STATUSES: JobStatus[] = ['Pending', 'In progress', 'Done'];

/** Mirror of nextDueOf() in Index.html: override wins, else odometer + catalog interval (interval 0 = repair, no due). */
export function nextDuePreview(override: number | null, odo: number | null | undefined, interval: number | null | undefined): number | null {
    if (override != null) return override;
    return odo != null && interval != null && interval > 0 ? odo + interval : null;
}

const parseKm = (s: string): number | null => {
    const n = parseInt(String(s).replace(/[^\d]/g, ''), 10);
    return Number.isFinite(n) ? n : null;
};
const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

const ServiceJobScreen = () => {
    const navigation = useNavigation<any>();
    const { params } = useRoute<any>();
    const theme = useTheme();
    const insets = useSafeAreaInsets();
    const { t, tv } = useServiceT();
    const { getVisit, catalog, saveJob, lists } = useServiceVisits();
    const visit = getVisit(params.visitId);
    const existing = params.jobId ? visit?.jobs?.find((j) => j.id === params.jobId || j.client_uuid === params.jobId) : undefined;

    const [search, setSearch] = useState('');
    const [picked, setPicked] = useState<CatalogItem | null>(() => (existing?.catalog_item_id ? catalog.find((c) => c.id === existing.catalog_item_id || c.uuid === existing.catalog_item_id) ?? null : null));
    const [serviceName, setServiceName] = useState(existing?.service_name ?? '');
    const [vendor, setVendor] = useState(existing?.vendor_name ?? '');
    const [approvalNeeded, setApprovalNeeded] = useState<'Yes' | 'No'>(existing?.approval_needed ? 'Yes' : 'No');
    const [status, setStatus] = useState<JobStatus>(existing?.status ?? 'Pending');
    const [parts, setParts] = useState(existing?.parts_replaced ?? '');
    const [override, setOverride] = useState(existing?.next_due_override_km != null ? String(existing.next_due_override_km) : '');
    const [dueDate, setDueDate] = useState(existing?.next_due_date ?? '');
    const [notes, setNotes] = useState(existing?.notes ?? '');
    const [busy, setBusy] = useState(false);

    const matches = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return [];
        return catalog.filter((c) => c.name.toLowerCase().includes(q) || (c.category || '').toLowerCase().includes(q)).slice(0, 25);
    }, [catalog, search]);

    const pick = useCallback((c: CatalogItem) => {
        setPicked(c);
        setServiceName(c.name);
        setVendor((v) => v || c.suggested_vendor_name || '');
        setSearch('');
    }, []);

    const overrideKm = parseKm(override);
    const preview = nextDuePreview(overrideKm, visit?.odometer, picked?.interval_km);
    const dateOk = !dueDate || DATE_RE.test(dueDate);
    const valid = serviceName.trim().length > 0 && dateOk;

    const onSave = useCallback(async () => {
        if (!visit || !valid) return;
        setBusy(true);
        try {
            await saveJob(
                visit.id,
                {
                    catalog_item_id: picked?.id ?? existing?.catalog_item_id ?? null,
                    service_name: serviceName.trim(),
                    vendor_name: vendor,
                    approval_needed: approvalNeeded === 'Yes',
                    status,
                    parts_replaced: parts,
                    next_due_override_km: overrideKm,
                    next_due_date: dueDate || null,
                    notes,
                },
                existing?.id
            );
            navigation.goBack();
        } finally {
            setBusy(false);
        }
    }, [visit, valid, saveJob, picked, existing, serviceName, vendor, approvalNeeded, status, parts, overrideKm, dueDate, notes, navigation]);

    if (!visit) return <EmptyState text={t('visit.notFound')} />;

    const input = (value: string, set: (s: string) => void, placeholder: string, extra: any = {}) => (
        <TextInput
            value={value}
            onChangeText={set}
            placeholder={placeholder}
            placeholderTextColor={theme.textSecondary?.val}
            style={{ minHeight: 46, borderWidth: 1, borderColor: theme.borderColor?.val, borderRadius: 12, paddingHorizontal: 12, color: theme.textPrimary?.val, backgroundColor: theme.surface?.val, textAlignVertical: extra.multiline ? 'top' : 'center' }}
            {...extra}
        />
    );

    return (
        <YStack flex={1} bg='$background'>
            <SyncBanner />
            <ScrollView contentContainerStyle={{ padding: 12, gap: 14, paddingBottom: 24 }} keyboardShouldPersistTaps='handled'>
                <YStack gap='$2'>
                    <FieldLabel>{t('job.service')}</FieldLabel>
                    {input(search, setSearch, t('job.searchCatalog'), { autoCorrect: false })}
                    {matches.map((c) => (
                        <Pressable key={c.id} onPress={() => pick(c)}>
                            <YStack bg='$surface' borderWidth={1} borderColor='$borderColor' borderRadius='$3' px='$3' py='$2'>
                                <Text color='$textPrimary' fontWeight='bold'>
                                    {c.name}
                                </Text>
                                <Text color='$textSecondary' fontSize={12}>
                                    {[tv('lists.category', c.category), c.lane, c.interval_km ? `${c.interval_km.toLocaleString('en-US')} ${t('common.km')}` : null].filter(Boolean).join(' · ')}
                                </Text>
                            </YStack>
                        </Pressable>
                    ))}
                    {search.trim() && matches.length === 0 ? (
                        <Pressable
                            onPress={() => {
                                setPicked(null);
                                setServiceName(search.trim());
                                setSearch('');
                            }}
                        >
                            <Text color='$infoText' px='$1'>
                                {t('job.useCustom', { name: search.trim() })}
                            </Text>
                        </Pressable>
                    ) : null}
                    {serviceName ? (
                        <Card>
                            <Text color='$textPrimary' fontWeight='bold' fontSize={16}>
                                {serviceName}
                            </Text>
                            {picked ? (
                                <Text color='$textSecondary' fontSize={12}>
                                    {[tv('lists.category', picked.category), picked.lane].filter(Boolean).join(' · ')}
                                </Text>
                            ) : null}
                        </Card>
                    ) : null}
                </YStack>

                <YStack gap='$2'>
                    <FieldLabel>{t('job.vendor')}</FieldLabel>
                    <ChipSelect options={(lists.vendors || []).filter(Boolean)} value={vendor} onChange={setVendor} />
                </YStack>

                <YStack gap='$2'>
                    <FieldLabel>{t('job.approvalNeeded')}</FieldLabel>
                    <ChipSelect options={['Yes', 'No'] as const} value={approvalNeeded} onChange={setApprovalNeeded} label={(v) => t(v === 'Yes' ? 'common.yes' : 'common.no')} />
                </YStack>

                <YStack gap='$2'>
                    <FieldLabel>{t('job.statusLabel')}</FieldLabel>
                    <ChipSelect options={JOB_STATUSES} value={status} onChange={setStatus} label={(v) => tv('job.status', v)} />
                </YStack>

                <YStack gap='$2'>
                    <FieldLabel>{t('job.parts')}</FieldLabel>
                    {input(parts, setParts, t('job.partsPlaceholder'), { multiline: true, numberOfLines: 3 })}
                </YStack>

                <YStack gap='$2'>
                    <FieldLabel>{t('job.nextDueOverride')}</FieldLabel>
                    {input(override, setOverride, t('job.nextDueOverridePlaceholder'), { keyboardType: 'number-pad' })}
                    {input(dueDate, setDueDate, t('job.nextDueDate'), { autoCapitalize: 'none', keyboardType: 'numbers-and-punctuation', maxLength: 10 })}
                    {!dateOk ? (
                        <Text color='$errorText' fontSize={12}>
                            {t('job.dateFormat')}
                        </Text>
                    ) : null}
                    <Text color='$textSecondary' fontSize={13}>
                        {t('job.nextDueIs')}:{' '}
                        <Text color='$textPrimary' fontWeight='bold'>
                            {preview != null ? `${preview.toLocaleString('en-US')} ${t('common.km')}` : t('job.nextDueNone')}
                        </Text>
                        {overrideKm != null ? ` (${t('job.overridden')})` : ''}
                    </Text>
                </YStack>

                <YStack gap='$2'>
                    <FieldLabel>{t('common.notes')}</FieldLabel>
                    {input(notes, setNotes, t('common.notes'), { multiline: true, numberOfLines: 3 })}
                </YStack>
            </ScrollView>
            <YStack pb={insets.bottom}>
                <SaveBar onPress={onSave} disabled={!valid} busy={busy} label={t('common.save')} />
            </YStack>
        </YStack>
    );
};

export default ServiceJobScreen;
