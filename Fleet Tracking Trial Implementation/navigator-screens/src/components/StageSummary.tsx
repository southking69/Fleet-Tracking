import React, { useMemo } from 'react';
import { YStack, XStack, Text } from 'tamagui';
import { useServiceT } from '../translations';
import { STAGE_SHORT, Visit, fmtHours, visitCalc } from '../services/visitModel';

const STAGE_KEYS = ['pickupWait', 'transitFirst', 'approvalWait', 'vendorWork', 'betweenVendors', 'returnTransit'];

/**
 * Turnaround summary for a visit: total vs target, the six stages as proportional bars and the
 * biggest delay. Numbers come from the server `calc` when present, otherwise from the local port
 * of calcVisit() so a visit recorded offline is summarised immediately.
 */
const StageSummary = ({ visit, compact = false }: { visit: Visit; compact?: boolean }) => {
    const { t } = useServiceT();
    const c = useMemo(() => visitCalc(visit), [visit]);
    const max = Math.max(0.01, ...c.stages.map((s) => (s == null ? 0 : s)));
    const headline = c.status === 'Closed' ? c.total : c.open;

    return (
        <YStack gap='$2' testID='stage-summary'>
            <XStack justifyContent='space-between' alignItems='center'>
                <YStack>
                    <Text color='$textSecondary' fontSize={12}>
                        {c.status === 'Closed' ? t('stage.total') : t('stage.openFor')}
                    </Text>
                    <Text color='$textPrimary' fontSize={22} fontWeight='bold'>
                        {fmtHours(headline)}
                    </Text>
                </YStack>
                <YStack alignItems='flex-end'>
                    <Text color='$textSecondary' fontSize={12}>
                        {t('stage.target')}
                    </Text>
                    <Text color='$textPrimary' fontSize={16} fontWeight='bold'>
                        {fmtHours(c.target)}
                    </Text>
                    {c.met != null ? (
                        <Text color={c.met ? '$successText' : '$errorText'} fontSize={12} fontWeight='bold'>
                            {c.met ? t('stage.metTarget') : t('stage.missedTarget')}
                        </Text>
                    ) : null}
                </YStack>
            </XStack>
            {!compact &&
                c.stages.map((s, i) => (
                    <YStack key={i} gap={2}>
                        <XStack justifyContent='space-between'>
                            <Text color={c.big === i ? '$errorText' : '$textSecondary'} fontSize={12} fontWeight={c.big === i ? 'bold' : 'normal'}>
                                {t(`stage.${STAGE_KEYS[i]}`)}
                            </Text>
                            <Text color='$textPrimary' fontSize={12}>
                                {fmtHours(s)}
                            </Text>
                        </XStack>
                        <XStack height={6} bg='$surface' borderRadius={3} overflow='hidden'>
                            <YStack width={`${s == null ? 0 : Math.max(2, Math.round((s / max) * 100))}%` as any} bg={c.big === i ? '$error' : '$info'} />
                        </XStack>
                    </YStack>
                ))}
            {c.big >= 0 ? (
                <Text color='$textSecondary' fontSize={12}>
                    {t('stage.biggestDelay')}: <Text fontWeight='bold' color='$textPrimary'>{t(`stage.${STAGE_KEYS[c.big]}`) || STAGE_SHORT[c.big]}</Text> ({c.owner})
                </Text>
            ) : null}
        </YStack>
    );
};

export default StageSummary;
