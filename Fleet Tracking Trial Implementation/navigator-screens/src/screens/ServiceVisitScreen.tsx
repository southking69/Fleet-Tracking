import React, { useCallback, useState } from 'react';
import { Alert, Pressable, ScrollView, TextInput } from 'react-native';
import { useNavigation, useRoute } from '@react-navigation/native';
import { YStack, XStack, Text, Button, useTheme } from 'tamagui';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { format } from 'date-fns';
import { useServiceVisits } from '../contexts/ServiceVisitContext';
import { useServiceT } from '../translations';
import OdometerNumber from '../components/OdometerNumber';
import StageSummary from '../components/StageSummary';
import { Card, ChipSelect, EmptyState, FieldLabel, PendingPill, SyncBanner } from '../components/ServiceUi';
import { stopInfo, fmtHours } from '../services/visitModel';

const fmtTime = (iso?: string | null) => (iso ? format(new Date(iso), 'dd MMM HH:mm') : '–');

const ServiceVisitScreen = () => {
    const navigation = useNavigation<any>();
    const { params } = useRoute<any>();
    const theme = useTheme();
    const insets = useSafeAreaInsets();
    const { t, tv } = useServiceT();
    const { getVisit, stampVisit, updateVisit, addVendorStop, lists } = useServiceVisits();
    const visit = getVisit(params.visitId);
    const [odo, setOdo] = useState('');
    const [editingOdo, setEditingOdo] = useState(false);

    const confirmStamp = useCallback(
        (field: 'arrived_at_lsa' | 'picked_up_at' | 'returned_at', current?: string | null) => {
            const run = () => void stampVisit(params.visitId, field);
            if (!current) return run();
            // never silently overwrite a recorded time
            Alert.alert(t('visit.restampTitle'), t('visit.restampBody', { time: fmtTime(current) }), [
                { text: t('common.cancel'), style: 'cancel' },
                { text: t('visit.restamp'), style: 'destructive', onPress: run },
            ]);
        },
        [params.visitId, stampVisit, t]
    );

    if (!visit) return <EmptyState text={t('visit.notFound')} />;

    const saveOdo = () => {
        const n = parseInt(odo.replace(/[^\d]/g, ''), 10);
        if (Number.isFinite(n)) void updateVisit(visit.id, { odometer: n });
        setEditingOdo(false);
    };

    const row = (key: string, { label, value, onPress, actionLabel }: { label: string; value?: string | null; onPress: () => void; actionLabel: string }) => (
        <XStack key={key} alignItems='center' justifyContent='space-between' py='$1.5' gap='$2'>
            <YStack flex={1}>
                <Text color='$textSecondary' fontSize={12}>
                    {label}
                </Text>
                <Text color='$textPrimary' fontSize={16} fontWeight='bold'>
                    {fmtTime(value)}
                </Text>
            </YStack>
            <Button size='$4' bg={value ? '$surface' : '$info'} borderWidth={1} borderColor={value ? '$borderColor' : '$infoBorder'} onPress={onPress}>
                <Button.Text color={value ? '$textPrimary' : '$infoText'} fontWeight='bold'>
                    {value ? t('visit.restamp') : actionLabel}
                </Button.Text>
            </Button>
        </XStack>
    );

    const inspectionBtn = (stage: 'pickup' | 'return', done?: boolean) => (
        <Button flex={1} height={52} bg={done ? '$success' : '$surface'} borderWidth={1} borderColor={done ? '$successBorder' : '$borderColor'} onPress={() => navigation.navigate('Inspection', { visitId: visit.id, stage })}>
            <Button.Text color={done ? '$successText' : '$textPrimary'} fontWeight='bold'>
                {t(`inspection.${stage}`)} {done ? '✓' : ''}
            </Button.Text>
        </Button>
    );

    return (
        <YStack flex={1} bg='$background'>
            <SyncBanner />
            <ScrollView contentContainerStyle={{ padding: 12, paddingBottom: insets.bottom + 32, gap: 12 }} keyboardShouldPersistTaps='handled'>
                <Card>
                    <XStack justifyContent='space-between' alignItems='center'>
                        <Text color='$textPrimary' fontSize={26} fontWeight='bold'>
                            {visit.plate}
                        </Text>
                        {visit._pending ? <PendingPill /> : null}
                    </XStack>
                    <Text color='$textSecondary'>{visit.make_model || '–'}</Text>
                    <XStack alignItems='center' justifyContent='space-between' mt='$2'>
                        <YStack>
                            <Text color='$textSecondary' fontSize={12}>
                                {t('visit.odometerKm')}
                            </Text>
                            {editingOdo ? (
                                <TextInput
                                    value={odo}
                                    onChangeText={setOdo}
                                    keyboardType='number-pad'
                                    autoFocus
                                    style={{ minWidth: 140, height: 44, borderWidth: 1, borderColor: theme.borderColor?.val, borderRadius: 10, paddingHorizontal: 10, color: theme.textPrimary?.val, fontSize: 18 }}
                                />
                            ) : visit.odometer != null ? (
                                <OdometerNumber value={visit.odometer} digitHeight={28} />
                            ) : (
                                <Text color='$warningText'>{t('visit.odometerMissing')}</Text>
                            )}
                        </YStack>
                        <Button
                            size='$3'
                            onPress={() => {
                                if (editingOdo) return saveOdo();
                                setOdo(visit.odometer != null ? String(visit.odometer) : '');
                                setEditingOdo(true);
                            }}
                        >
                            <Button.Text>{editingOdo ? t('common.save') : t('common.edit')}</Button.Text>
                        </Button>
                    </XStack>
                </Card>

                <Card>
                    <StageSummary visit={visit} />
                </Card>

                <Card>
                    <FieldLabel>{t('visit.timeline')}</FieldLabel>
                    {row('arrived_at_lsa', { label: t('visit.arrivedAtLsa'), value: visit.arrived_at_lsa, actionLabel: t('visit.markNow'), onPress: () => confirmStamp('arrived_at_lsa', visit.arrived_at_lsa) })}
                    {row('picked_up_at', { label: t('visit.pickedUp'), value: visit.picked_up_at, actionLabel: t('visit.markNow'), onPress: () => confirmStamp('picked_up_at', visit.picked_up_at) })}
                    {row('returned_at', { label: t('visit.returnedAtLsa'), value: visit.returned_at, actionLabel: t('visit.markNow'), onPress: () => confirmStamp('returned_at', visit.returned_at) })}
                </Card>

                <Card>
                    <FieldLabel>{t('inspection.title')}</FieldLabel>
                    <XStack gap='$2'>
                        {inspectionBtn('pickup', visit.pickup_inspected)}
                        {inspectionBtn('return', visit.return_inspected)}
                    </XStack>
                </Card>

                <Card>
                    <FieldLabel>{t('visit.stops')}</FieldLabel>
                    {(visit.stops || []).length === 0 ? (
                        <Text color='$textSecondary'>{t('visit.noStops')}</Text>
                    ) : (
                        (visit.stops || []).map((s) => {
                            const i = stopInfo(s);
                            return (
                                <Pressable key={s.id} onPress={() => navigation.navigate('VendorStop', { visitId: visit.id, stopId: s.id })}>
                                    <XStack py='$2' justifyContent='space-between' alignItems='center' borderTopWidth={1} borderColor='$borderColor'>
                                        <YStack flex={1}>
                                            <Text color='$textPrimary' fontWeight='bold'>
                                                {s.vendor_name}
                                            </Text>
                                            <Text color='$textSecondary' fontSize={12}>
                                                {fmtTime(s.arrived_at)} {'→'} {fmtTime(s.left_at)} {i.atVendor != null ? `(${fmtHours(i.atVendor)})` : ''}
                                            </Text>
                                        </YStack>
                                        {s._pending ? <PendingPill /> : null}
                                    </XStack>
                                </Pressable>
                            );
                        })
                    )}
                    <Text color='$textSecondary' fontSize={12} mt='$1'>
                        {t('visit.addStop')}
                    </Text>
                    <ChipSelect
                        options={(lists.vendors || []).filter(Boolean)}
                        onChange={async (name) => {
                            const stopId = await addVendorStop(visit.id, name);
                            navigation.navigate('VendorStop', { visitId: visit.id, stopId });
                        }}
                    />
                </Card>

                <Card>
                    <XStack justifyContent='space-between' alignItems='center'>
                        <FieldLabel>{t('visit.jobs')}</FieldLabel>
                        <Button size='$3' onPress={() => navigation.navigate('ServiceJob', { visitId: visit.id })}>
                            <Button.Text>{t('job.add')}</Button.Text>
                        </Button>
                    </XStack>
                    {(visit.jobs || []).length === 0 ? <Text color='$textSecondary'>{t('visit.noJobs')}</Text> : null}
                    {(visit.jobs || []).map((j) => (
                        <Pressable key={j.id} onPress={() => navigation.navigate('ServiceJob', { visitId: visit.id, jobId: j.id })}>
                            <XStack py='$2' justifyContent='space-between' alignItems='center' borderTopWidth={1} borderColor='$borderColor' gap='$2'>
                                <YStack flex={1}>
                                    <Text color='$textPrimary' fontWeight='bold' numberOfLines={2}>
                                        {j.service_name}
                                    </Text>
                                    <Text color='$textSecondary' fontSize={12}>
                                        {j.vendor_name || '–'} {'·'} {tv('job.status', j.status)}
                                        {j.approval_needed ? ` · ${t('job.approvalNeeded')}` : ''}
                                    </Text>
                                </YStack>
                                {j._pending ? <PendingPill /> : null}
                            </XStack>
                        </Pressable>
                    ))}
                </Card>

                <Card>
                    <XStack justifyContent='space-between' alignItems='center'>
                        <FieldLabel>{t('damage.title')}</FieldLabel>
                        <Button size='$3' onPress={() => navigation.navigate('CreateDamage', { visitId: visit.id })}>
                            <Button.Text>{t('damage.add')}</Button.Text>
                        </Button>
                    </XStack>
                    {(visit.damage_records || []).length === 0 ? <Text color='$textSecondary'>{t('damage.none')}</Text> : null}
                    {(visit.damage_records || []).map((d) => (
                        <XStack key={d.id} py='$2' borderTopWidth={1} borderColor='$borderColor' justifyContent='space-between' alignItems='center'>
                            <YStack flex={1}>
                                <Text color='$textPrimary' fontWeight='bold'>
                                    {[d.location, d.damage_type].filter(Boolean).join(' · ')}
                                </Text>
                                <Text color='$textSecondary' fontSize={12}>
                                    {[d.severity, d.condition, d.stage].filter(Boolean).join(' · ')} {'·'} {d.photos?.length ?? 0} {t('common.photos')}
                                </Text>
                            </YStack>
                            {d._pending ? <PendingPill /> : null}
                        </XStack>
                    ))}
                </Card>

                <Button height={52} bg='$surface' borderWidth={1} borderColor='$borderColor' onPress={() => navigation.navigate('CreatePettyCash', { visitId: visit.id, plate: visit.plate })}>
                    <Button.Text color='$textPrimary' fontWeight='bold'>
                        {t('petty.addForVisit')}
                    </Button.Text>
                </Button>
            </ScrollView>
        </YStack>
    );
};

export default ServiceVisitScreen;
