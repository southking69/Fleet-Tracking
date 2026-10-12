import React, { useCallback, useMemo, useState } from 'react';
import { FlatList, Pressable, RefreshControl, TextInput } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import { YStack, XStack, Text, Button, useTheme } from 'tamagui';
import { FontAwesomeIcon } from '@fortawesome/react-native-fontawesome';
import { faPlus, faWallet, faGaugeHigh } from '@fortawesome/free-solid-svg-icons';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useServiceVisits } from '../contexts/ServiceVisitContext';
import { useServiceT } from '../translations';
import StageSummary from '../components/StageSummary';
import { Card, EmptyState, PendingPill, SyncBanner } from '../components/ServiceUi';
import { Visit, visitCalc } from '../services/visitModel';

const STATUS_TONE: Record<string, { bg: string; border: string; text: string; key: string }> = {
    'Not started': { bg: '$secondary', border: '$borderColor', text: '$textSecondary', key: 'visit.status.not_started' },
    'In progress': { bg: '$info', border: '$infoBorder', text: '$infoText', key: 'visit.status.in_progress' },
    Closed: { bg: '$success', border: '$successBorder', text: '$successText', key: 'visit.status.closed' },
};

const ServiceVisitListScreen = () => {
    const navigation = useNavigation<any>();
    const theme = useTheme();
    const insets = useSafeAreaInsets();
    const { t } = useServiceT();
    const { visits, refresh, isRefreshing, createVisit, queue } = useServiceVisits();
    const [query, setQuery] = useState('');
    const [showNew, setShowNew] = useState(false);
    const [plate, setPlate] = useState('');
    const [makeModel, setMakeModel] = useState('');
    const [odo, setOdo] = useState('');
    const [filter, setFilter] = useState<'open' | 'all'>('open');

    const rows = useMemo(() => {
        const q = query.replace(/\s+/g, '').toUpperCase();
        return visits.filter((v) => {
            if (filter === 'open' && v.returned_at) return false;
            return !q || v.plate.replace(/\s+/g, '').toUpperCase().includes(q) || (v.make_model || '').toUpperCase().includes(q);
        });
    }, [visits, query, filter]);

    const handleCreate = useCallback(async () => {
        if (!plate.trim()) return;
        const n = parseInt(odo.replace(/[^\d]/g, ''), 10);
        const id = await createVisit({ plate, make_model: makeModel, odometer: Number.isFinite(n) ? n : null });
        setPlate('');
        setMakeModel('');
        setOdo('');
        setShowNew(false);
        navigation.navigate('ServiceVisit', { visitId: id });
    }, [plate, makeModel, odo, createVisit, navigation]);

    const renderItem = ({ item: v }: { item: Visit }) => {
        const c = visitCalc(v);
        const tone = STATUS_TONE[c.status];
        const overdue = (v as any).calc?.overdueN as number | undefined;
        return (
            <Pressable onPress={() => navigation.navigate('ServiceVisit', { visitId: v.id })} accessibilityRole='button'>
                <Card mx='$3' my='$1.5' gap='$2'>
                    <XStack justifyContent='space-between' alignItems='center'>
                        <YStack flex={1}>
                            <Text color='$textPrimary' fontSize={20} fontWeight='bold' numberOfLines={1}>
                                {v.plate}
                            </Text>
                            <Text color='$textSecondary' numberOfLines={1}>
                                {v.make_model || '–'}
                            </Text>
                        </YStack>
                        <YStack alignItems='flex-end' gap='$1'>
                            <XStack bg={tone.bg as any} borderWidth={1} borderColor={tone.border as any} borderRadius='$3' px='$2' py='$1'>
                                <Text color={tone.text as any} fontSize={12} fontWeight='bold'>
                                    {t(tone.key)}
                                </Text>
                            </XStack>
                            {v._pending ? <PendingPill /> : null}
                        </YStack>
                    </XStack>
                    <StageSummary visit={v} compact />
                    <XStack gap='$3' flexWrap='wrap'>
                        <Text color='$textSecondary' fontSize={12}>
                            {t('visit.stopsCount', { n: v.stops?.length ?? 0 })}
                        </Text>
                        <Text color='$textSecondary' fontSize={12}>
                            {t('visit.jobsCount', { n: v.jobs?.length ?? 0 })}
                        </Text>
                        <Text color='$textSecondary' fontSize={12}>
                            {t('visit.damageCount', { n: v.damage_records?.length ?? 0 })}
                        </Text>
                        {overdue ? (
                            <Text color='$errorText' fontSize={12} fontWeight='bold'>
                                {t('visit.overdueCount', { n: overdue })}
                            </Text>
                        ) : null}
                    </XStack>
                </Card>
            </Pressable>
        );
    };

    return (
        <YStack flex={1} bg='$background'>
            <SyncBanner />
            <XStack px='$3' pt='$3' gap='$2' alignItems='center'>
                <TextInput
                    value={query}
                    onChangeText={setQuery}
                    placeholder={t('visit.searchPlate')}
                    placeholderTextColor={theme.textSecondary?.val}
                    autoCapitalize='characters'
                    style={{ flex: 1, height: 44, borderWidth: 1, borderColor: theme.borderColor?.val, borderRadius: 12, paddingHorizontal: 12, color: theme.textPrimary?.val, backgroundColor: theme.surface?.val }}
                />
                <Pressable onPress={() => navigation.navigate('PettyCashList')} hitSlop={8} accessibilityLabel={t('nav.pettyCash')}>
                    <YStack width={44} height={44} borderRadius='$4' borderWidth={1} borderColor='$borderColor' bg='$surface' alignItems='center' justifyContent='center'>
                        <FontAwesomeIcon icon={faWallet} color={theme.textPrimary?.val} size={18} />
                    </YStack>
                </Pressable>
                <Pressable onPress={() => navigation.navigate('VehicleDue')} hitSlop={8} accessibilityLabel={t('nav.vehiclesDue')}>
                    <YStack width={44} height={44} borderRadius='$4' borderWidth={1} borderColor='$borderColor' bg='$surface' alignItems='center' justifyContent='center'>
                        <FontAwesomeIcon icon={faGaugeHigh} color={theme.textPrimary?.val} size={18} />
                    </YStack>
                </Pressable>
            </XStack>
            <XStack px='$3' py='$2' gap='$2'>
                {(['open', 'all'] as const).map((f) => (
                    <Pressable key={f} onPress={() => setFilter(f)}>
                        <XStack px='$3' py='$1.5' borderRadius='$5' borderWidth={1} bg={filter === f ? '$info' : '$surface'} borderColor={filter === f ? '$infoBorder' : '$borderColor'}>
                            <Text color={filter === f ? '$infoText' : '$textPrimary'} fontWeight={filter === f ? 'bold' : 'normal'}>
                                {t(`visit.filter.${f}`)}
                            </Text>
                        </XStack>
                    </Pressable>
                ))}
            </XStack>

            {showNew ? (
                <Card mx='$3' mb='$2'>
                    <Text color='$textPrimary' fontWeight='bold' fontSize={16}>
                        {t('visit.new')}
                    </Text>
                    {[
                        [t('visit.plate'), plate, setPlate, 'characters', 'default'],
                        [t('visit.makeModel'), makeModel, setMakeModel, 'words', 'default'],
                        [t('visit.odometerKm'), odo, setOdo, 'none', 'number-pad'],
                    ].map(([label, val, set, cap, kb]: any) => (
                        <TextInput
                            key={label}
                            value={val}
                            onChangeText={set}
                            placeholder={label}
                            placeholderTextColor={theme.textSecondary?.val}
                            autoCapitalize={cap}
                            keyboardType={kb}
                            style={{ height: 46, borderWidth: 1, borderColor: theme.borderColor?.val, borderRadius: 12, paddingHorizontal: 12, color: theme.textPrimary?.val, backgroundColor: theme.background?.val }}
                        />
                    ))}
                    <XStack gap='$2'>
                        <Button flex={1} onPress={() => setShowNew(false)}>
                            <Button.Text>{t('common.cancel')}</Button.Text>
                        </Button>
                        <Button flex={1} bg='$info' borderWidth={1} borderColor='$infoBorder' disabled={!plate.trim()} opacity={plate.trim() ? 1 : 0.55} onPress={handleCreate}>
                            <Button.Text color='$infoText' fontWeight='bold'>
                                {t('visit.start')}
                            </Button.Text>
                        </Button>
                    </XStack>
                </Card>
            ) : null}

            <FlatList
                data={rows}
                keyExtractor={(v) => v.id}
                renderItem={renderItem}
                refreshControl={<RefreshControl refreshing={isRefreshing} onRefresh={refresh} />}
                contentContainerStyle={{ paddingBottom: insets.bottom + 90, flexGrow: 1 }}
                ListEmptyComponent={<EmptyState text={queue.online ? t('visit.empty') : t('visit.emptyOffline')} />}
            />

            {!showNew ? (
                <YStack position='absolute' right={16} bottom={insets.bottom + 16}>
                    <Button circular size='$6' bg='$info' borderWidth={1} borderColor='$infoBorder' onPress={() => setShowNew(true)} accessibilityLabel={t('visit.new')}>
                        <Button.Icon>
                            <FontAwesomeIcon icon={faPlus} color={theme.infoText?.val} size={20} />
                        </Button.Icon>
                    </Button>
                </YStack>
            ) : null}
        </YStack>
    );
};

export default ServiceVisitListScreen;
