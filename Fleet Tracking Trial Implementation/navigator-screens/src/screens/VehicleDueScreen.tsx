import React, { useCallback, useMemo, useState } from 'react';
import { FlatList, Pressable, RefreshControl, TextInput } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { YStack, XStack, Text, useTheme } from 'tamagui';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useServiceVisits } from '../contexts/ServiceVisitContext';
import { useServiceT } from '../translations';
import DueBadge from '../components/DueBadge';
import OdometerNumber from '../components/OdometerNumber';
import { Card, EmptyState, SyncBanner } from '../components/ServiceUi';
import { DueStatus, VehicleDue, dueStatusFromLeft } from '../services/visitModel';

const RANK: Record<DueStatus, number> = { OVERDUE: 0, 'Due soon': 1, 'Odometer missing': 2, 'No previous due': 3, 'On time': 4 };
const statusOf = (i: VehicleDue['items'][number]): DueStatus => i.status ?? dueStatusFromLeft(i.km_left);

/** Worst status first, so the cars that need attention are at the top. */
export function worstStatus(v: VehicleDue): DueStatus {
    return v.items.map(statusOf).sort((a, b) => RANK[a] - RANK[b])[0] ?? 'No previous due';
}

const VehicleDueScreen = () => {
    const theme = useTheme();
    const insets = useSafeAreaInsets();
    const { t } = useServiceT();
    const { vehiclesDue, loadVehiclesDue } = useServiceVisits();
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState<string | null>(null);
    const [refreshing, setRefreshing] = useState(false);

    useFocusEffect(
        useCallback(() => {
            void loadVehiclesDue();
        }, [loadVehiclesDue])
    );

    const rows = useMemo(() => {
        const q = query.replace(/\s+/g, '').toUpperCase();
        return vehiclesDue
            .filter((v) => !q || v.plate.replace(/\s+/g, '').toUpperCase().includes(q))
            .sort((a, b) => RANK[worstStatus(a)] - RANK[worstStatus(b)] || a.plate.localeCompare(b.plate));
    }, [vehiclesDue, query]);

    const onRefresh = useCallback(async () => {
        setRefreshing(true);
        await loadVehiclesDue();
        setRefreshing(false);
    }, [loadVehiclesDue]);

    return (
        <YStack flex={1} bg='$background'>
            <SyncBanner />
            <XStack px='$3' pt='$3' pb='$2'>
                <TextInput
                    value={query}
                    onChangeText={setQuery}
                    placeholder={t('visit.searchPlate')}
                    placeholderTextColor={theme.textSecondary?.val}
                    autoCapitalize='characters'
                    style={{ flex: 1, height: 44, borderWidth: 1, borderColor: theme.borderColor?.val, borderRadius: 12, paddingHorizontal: 12, color: theme.textPrimary?.val, backgroundColor: theme.surface?.val }}
                />
            </XStack>
            <FlatList
                data={rows}
                keyExtractor={(v) => v.plate}
                refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} />}
                contentContainerStyle={{ paddingBottom: insets.bottom + 24, flexGrow: 1 }}
                ListEmptyComponent={<EmptyState text={t('due.empty')} />}
                renderItem={({ item: v }) => {
                    const expanded = open === v.plate;
                    return (
                        <Pressable onPress={() => setOpen(expanded ? null : v.plate)}>
                            <Card mx='$3' my='$1.5'>
                                <XStack justifyContent='space-between' alignItems='center'>
                                    <YStack flex={1}>
                                        <Text color='$textPrimary' fontSize={20} fontWeight='bold'>
                                            {v.plate}
                                        </Text>
                                        <Text color='$textSecondary'>{v.make_model || '–'}</Text>
                                    </YStack>
                                    <DueBadge status={worstStatus(v)} />
                                </XStack>
                                {v.last_odometer != null ? (
                                    <XStack alignItems='center' gap='$2'>
                                        <Text color='$textSecondary' fontSize={12}>
                                            {t('due.lastOdometer')}
                                        </Text>
                                        <OdometerNumber value={v.last_odometer} digitHeight={20} />
                                    </XStack>
                                ) : null}
                                {expanded
                                    ? v.items.map((i, idx) => (
                                          <XStack key={`${i.service_name}-${idx}`} justifyContent='space-between' alignItems='center' borderTopWidth={1} borderColor='$borderColor' pt='$2' gap='$2'>
                                              <YStack flex={1}>
                                                  <Text color='$textPrimary' numberOfLines={2}>
                                                      {i.service_name}
                                                  </Text>
                                                  <Text color='$textSecondary' fontSize={12}>
                                                      {t('due.nextDue')}: {i.next_due_km != null ? `${i.next_due_km.toLocaleString('en-US')} ${t('common.km')}` : '–'}
                                                  </Text>
                                              </YStack>
                                              <DueBadge status={statusOf(i)} kmLeft={i.km_left} showKm compact />
                                          </XStack>
                                      ))
                                    : null}
                            </Card>
                        </Pressable>
                    );
                }}
            />
        </YStack>
    );
};

export default VehicleDueScreen;
