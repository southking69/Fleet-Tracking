import React, { useCallback } from 'react';
import { Alert, Pressable, ScrollView } from 'react-native';
import { useRoute } from '@react-navigation/native';
import { YStack, XStack, Text } from 'tamagui';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { format } from 'date-fns';
import { useServiceVisits } from '../contexts/ServiceVisitContext';
import { useServiceT } from '../translations';
import { EmptyState, PendingPill, SyncBanner } from '../components/ServiceUi';
import { StopAction, VendorStop, fmtHours, stopInfo } from '../services/visitModel';

interface Step {
    action: StopAction;
    field: keyof VendorStop;
    labelKey: string;
    tone: 'info' | 'warning' | 'success' | 'default';
}

const STEPS: Step[] = [
    { action: 'check-in', field: 'arrived_at', labelKey: 'vendor.arrived', tone: 'info' },
    { action: 'approval-requested', field: 'approval_requested_at', labelKey: 'vendor.approvalRequested', tone: 'warning' },
    { action: 'approval-received', field: 'approval_received_at', labelKey: 'vendor.approvalReceived', tone: 'success' },
    { action: 'left', field: 'left_at', labelKey: 'vendor.left', tone: 'default' },
];

/**
 * Four big buttons. Each tap stamps the CURRENT device time immediately (optimistic, shown at once)
 * and queues the write; delivery order and offline are handled by the offline queue. The stamped time is
 * the moment of the tap, never the moment the upload eventually succeeds.
 */
const VendorStopScreen = () => {
    const { params } = useRoute<any>();
    const insets = useSafeAreaInsets();
    const { t } = useServiceT();
    const { getVisit, stampStop } = useServiceVisits();
    const visit = getVisit(params.visitId);
    const stop = visit?.stops?.find((s) => s.id === params.stopId || s.client_uuid === params.stopId);

    const press = useCallback(
        (step: Step) => {
            if (!visit || !stop) return;
            const existing = stop[step.field] as string | null | undefined;
            const run = () => void stampStop(visit.id, stop.id, step.action);
            if (!existing) return run();
            Alert.alert(t('vendor.restampTitle'), t('vendor.restampBody', { time: format(new Date(existing), 'HH:mm:ss') }), [
                { text: t('common.cancel'), style: 'cancel' },
                { text: t('vendor.restamp'), style: 'destructive', onPress: run },
            ]);
        },
        [visit, stop, stampStop, t]
    );

    if (!visit || !stop) return <EmptyState text={t('vendor.notFound')} />;

    const info = stopInfo(stop);
    // the next button to press = first unstamped in order
    const nextIdx = STEPS.findIndex((s) => !stop[s.field]);

    return (
        <YStack flex={1} bg='$background'>
            <SyncBanner />
            <ScrollView contentContainerStyle={{ padding: 12, paddingBottom: insets.bottom + 24, gap: 12 }}>
                <YStack gap='$1'>
                    <Text color='$textPrimary' fontSize={24} fontWeight='bold'>
                        {stop.vendor_name}
                    </Text>
                    <XStack gap='$2' alignItems='center'>
                        <Text color='$textSecondary'>{visit.plate}</Text>
                        {stop._pending ? <PendingPill /> : null}
                    </XStack>
                </YStack>

                {STEPS.map((step, i) => {
                    const at = stop[step.field] as string | null | undefined;
                    const done = !!at;
                    const isNext = i === nextIdx;
                    const bg = done ? '$surface' : (`$${step.tone === 'default' ? 'secondary' : step.tone}` as any);
                    const border = done ? '$successBorder' : isNext ? '$textPrimary' : (`$${step.tone === 'default' ? 'borderColor' : step.tone + 'Border'}` as any);
                    return (
                        <Pressable key={step.action} onPress={() => press(step)} accessibilityRole='button' accessibilityLabel={t(step.labelKey)} testID={`stamp-${step.action}`}>
                            <YStack minHeight={104} bg={bg} borderWidth={isNext ? 3 : 1} borderColor={border} borderRadius='$5' px='$4' py='$3' justifyContent='center' gap='$1'>
                                <XStack justifyContent='space-between' alignItems='center'>
                                    <Text color={done ? '$textPrimary' : (`$${step.tone === 'default' ? 'textPrimary' : step.tone + 'Text'}` as any)} fontSize={22} fontWeight='bold' flex={1}>
                                        {t(step.labelKey)}
                                    </Text>
                                    {done ? (
                                        <Text color='$successText' fontSize={22}>
                                            {'✓'}
                                        </Text>
                                    ) : null}
                                </XStack>
                                <Text color={done ? '$textSecondary' : '$textPrimary'} fontSize={16}>
                                    {done ? format(new Date(at as string), 'dd MMM yyyy  HH:mm:ss') : isNext ? t('vendor.tapToStamp') : t('vendor.notYet')}
                                </Text>
                            </YStack>
                        </Pressable>
                    );
                })}

                <YStack bg='$surface' borderWidth={1} borderColor='$borderColor' borderRadius='$4' p='$3' gap='$1.5'>
                    <XStack justifyContent='space-between'>
                        <Text color='$textSecondary'>{t('vendor.atVendor')}</Text>
                        <Text color='$textPrimary' fontWeight='bold'>
                            {fmtHours(info.atVendor)}
                        </Text>
                    </XStack>
                    <XStack justifyContent='space-between'>
                        <Text color='$textSecondary'>{t('vendor.approvalWait')}</Text>
                        <Text color='$textPrimary' fontWeight='bold'>
                            {fmtHours(info.appr)}
                        </Text>
                    </XStack>
                    <XStack justifyContent='space-between'>
                        <Text color='$textSecondary'>{t('vendor.netWork')}</Text>
                        <Text color='$textPrimary' fontWeight='bold'>
                            {fmtHours(info.net)}
                        </Text>
                    </XStack>
                </YStack>
            </ScrollView>
        </YStack>
    );
};

export default VendorStopScreen;
