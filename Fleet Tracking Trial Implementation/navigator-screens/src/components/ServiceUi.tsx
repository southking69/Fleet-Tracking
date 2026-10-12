import React from 'react';
import { Pressable } from 'react-native';
import { YStack, XStack, Text, Button, Spinner } from 'tamagui';
import { useServiceT } from '../translations';
import { useServiceVisits } from '../contexts/ServiceVisitContext';

/** Small shared building blocks for the Service Tracker screens (kept local so the fork needs no other change). */

export const FieldLabel = ({ children }: { children: React.ReactNode }) => (
    <Text color='$textPrimary' fontSize={16} fontWeight='bold' px='$1'>
        {children}
    </Text>
);

export const Card = ({ children, ...rest }: any) => (
    <YStack bg='$surface' borderWidth={1} borderColor='$borderColor' borderRadius='$4' p='$3' gap='$2' {...rest}>
        {children}
    </YStack>
);

interface ChipSelectProps<TV extends string> {
    value?: TV | '';
    options: readonly TV[];
    onChange: (v: TV) => void;
    label?: (v: TV) => string;
}
/** Single-choice chips for short enums (severity, condition, job status, yes/no). */
export function ChipSelect<TV extends string>({ value, options, onChange, label }: ChipSelectProps<TV>) {
    return (
        <XStack flexWrap='wrap' gap='$2'>
            {options.map((o) => {
                const on = o === value;
                return (
                    <Pressable key={o} onPress={() => onChange(o)} accessibilityRole='button' accessibilityState={{ selected: on }} style={{ minHeight: 44 }}>
                        <XStack minHeight={44} px='$3.5' alignItems='center' borderRadius='$5' borderWidth={1} bg={on ? '$info' : '$surface'} borderColor={on ? '$infoBorder' : '$borderColor'}>
                            <Text color={on ? '$infoText' : '$textPrimary'} fontWeight={on ? 'bold' : 'normal'}>
                                {label ? label(o) : o}
                            </Text>
                        </XStack>
                    </Pressable>
                );
            })}
        </XStack>
    );
}

export const SaveBar = ({ onPress, disabled, busy, label }: { onPress: () => void; disabled?: boolean; busy?: boolean; label: string }) => (
    <YStack bg='$background' borderTopWidth={1} borderColor='$borderColor' px='$3' py='$3'>
        <Button onPress={onPress} bg='$info' borderWidth={1} borderColor='$infoBorder' height={52} disabled={disabled || busy} opacity={disabled || busy ? 0.55 : 1}>
            {busy ? <Spinner color='$infoText' /> : null}
            <Button.Text color='$infoText' fontSize={16} fontWeight='bold'>
                {label}
            </Button.Text>
        </Button>
    </YStack>
);

/** Offline / syncing / failed strip. Shown at the top of every Service Tracker screen. */
export const SyncBanner = () => {
    const { t } = useServiceT();
    const { queue, failedItems, retryFailed, discardFailed } = useServiceVisits();
    const waiting = queue.pendingRecords + queue.pendingPhotos;
    if (!waiting && !failedItems.length) return null;
    const failed = failedItems.length > 0;
    return (
        <YStack bg={failed ? '$error' : '$warning'} borderBottomWidth={1} borderColor={failed ? '$errorBorder' : '$warningBorder'} px='$3' py='$2' gap='$1.5' testID='sync-banner'>
            {waiting > 0 ? (
                <XStack alignItems='center' gap='$2'>
                    {queue.flushing ? <Spinner size='small' color={failed ? '$errorText' : '$warningText'} /> : null}
                    <Text color={failed ? '$errorText' : '$warningText'} fontSize={13} fontWeight='bold' flex={1}>
                        {queue.online ? (queue.flushing ? t('sync.syncing') : t('sync.waitingRetry')) : t('sync.offline')} {t('sync.counts', { records: queue.pendingRecords, photos: queue.pendingPhotos })}
                    </Text>
                </XStack>
            ) : null}
            {failedItems.map((f) => (
                <XStack key={f.clientUuid} alignItems='center' gap='$2'>
                    <Text color='$errorText' fontSize={12} flex={1} numberOfLines={2}>
                        {t('sync.failed')}: {f.label || f.kind} - {f.lastError}
                    </Text>
                    <Pressable onPress={() => retryFailed(f.clientUuid)} hitSlop={8}>
                        <Text color='$errorText' fontWeight='bold'>
                            {t('common.retry')}
                        </Text>
                    </Pressable>
                    <Pressable onPress={() => discardFailed(f.clientUuid)} hitSlop={8}>
                        <Text color='$errorText' fontWeight='bold'>
                            {t('sync.discard')}
                        </Text>
                    </Pressable>
                </XStack>
            ))}
        </YStack>
    );
};

/** Little pill for rows that exist only on this device so far. */
export const PendingPill = () => {
    const { t } = useServiceT();
    return (
        <XStack alignSelf='flex-start' bg='$warning' borderWidth={1} borderColor='$warningBorder' borderRadius='$3' px='$1.5' py={1}>
            <Text color='$warningText' fontSize={11} fontWeight='bold'>
                {t('sync.pending')}
            </Text>
        </XStack>
    );
};

export const EmptyState = ({ text }: { text: string }) => (
    <YStack flex={1} alignItems='center' justifyContent='center' p='$6'>
        <Text color='$textSecondary' textAlign='center'>
            {text}
        </Text>
    </YStack>
);
