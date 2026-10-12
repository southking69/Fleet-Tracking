import React from 'react';
import { XStack, Text } from 'tamagui';
import { useServiceT } from '../translations';
import { DueStatus, dueStatusFromLeft } from '../services/visitModel';

interface Props {
    /** explicit status (as returned by the server); wins over kmLeft */
    status?: DueStatus | null;
    /** km remaining until due; negative = overdue. Used when status is not given. */
    kmLeft?: number | null;
    /** show "+1,234 km" next to the label */
    showKm?: boolean;
    compact?: boolean;
}

const TONE: Record<DueStatus, { bg: string; border: string; text: string; key: string }> = {
    OVERDUE: { bg: '$error', border: '$errorBorder', text: '$errorText', key: 'due.overdue' },
    'Due soon': { bg: '$warning', border: '$warningBorder', text: '$warningText', key: 'due.dueSoon' },
    'On time': { bg: '$success', border: '$successBorder', text: '$successText', key: 'due.onTime' },
    'Odometer missing': { bg: '$warning', border: '$warningBorder', text: '$warningText', key: 'due.odometerMissing' },
    'No previous due': { bg: '$secondary', border: '$borderColor', text: '$textSecondary', key: 'due.noPrevious' },
};

const fmt = (n: number) => Math.abs(Math.round(n)).toLocaleString('en-US');

/** Overdue / Due soon / On time pill. Same thresholds as the desktop tracker (<0 overdue, <=1000 km soon). */
const DueBadge = ({ status, kmLeft, showKm = false, compact = false }: Props) => {
    const { t } = useServiceT();
    const s: DueStatus = status ?? dueStatusFromLeft(kmLeft);
    const tone = TONE[s] ?? TONE['No previous due'];
    return (
        <XStack alignSelf='flex-start' alignItems='center' gap='$1.5' bg={tone.bg as any} borderWidth={1} borderColor={tone.border as any} borderRadius='$3' px={compact ? '$1.5' : '$2'} py={compact ? 2 : '$1'} testID={`due-badge-${s}`}>
            <Text color={tone.text as any} fontSize={compact ? 11 : 13} fontWeight='bold' numberOfLines={1}>
                {t(tone.key)}
            </Text>
            {showKm && kmLeft != null ? (
                <Text color={tone.text as any} fontSize={compact ? 11 : 12} numberOfLines={1}>
                    {kmLeft < 0 ? `+${fmt(kmLeft)}` : `${fmt(kmLeft)}`} {t('common.km')}
                </Text>
            ) : null}
        </XStack>
    );
};

export default DueBadge;
