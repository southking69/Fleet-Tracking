import React, { useMemo } from 'react';
import { FlatList, RefreshControl } from 'react-native';
import { useNavigation, useRoute } from '@react-navigation/native';
import { YStack, XStack, Text, Button, useTheme } from 'tamagui';
import { FontAwesomeIcon } from '@fortawesome/react-native-fontawesome';
import { faPlus } from '@fortawesome/free-solid-svg-icons';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { format } from 'date-fns';
import { useServiceVisits } from '../contexts/ServiceVisitContext';
import { useServiceT } from '../translations';
import { Card, EmptyState, PendingPill, SyncBanner } from '../components/ServiceUi';
import { PettyCashEntry, fromMinorUnits } from '../services/visitModel';

const PettyCashListScreen = () => {
    const navigation = useNavigation<any>();
    const route = useRoute<any>();
    const theme = useTheme();
    const insets = useSafeAreaInsets();
    const { t, tv } = useServiceT();
    const { petty, refresh, isRefreshing } = useServiceVisits();
    const visitId: string | undefined = route.params?.visitId;

    const rows = useMemo(() => (visitId ? petty.filter((p) => p.service_visit_id === visitId) : petty), [petty, visitId]);
    const total = useMemo(() => rows.reduce((a, p) => a + (p.amount || 0), 0), [rows]);
    const currency = rows[0]?.currency || 'SAR';

    const renderItem = ({ item: p }: { item: PettyCashEntry }) => (
        <Card mx='$3' my='$1.5'>
            <XStack justifyContent='space-between' alignItems='center'>
                <YStack flex={1}>
                    <Text color='$textPrimary' fontWeight='bold' fontSize={16}>
                        {tv('lists.expenseCat', p.category)}
                    </Text>
                    <Text color='$textSecondary' fontSize={12}>
                        {p.entry_date ? format(new Date(p.entry_date), 'dd MMM yyyy') : ''} {p.plate ? `· ${p.plate}` : ''}
                    </Text>
                </YStack>
                <YStack alignItems='flex-end' gap='$1'>
                    <Text color='$textPrimary' fontSize={18} fontWeight='bold'>
                        {fromMinorUnits(p.amount)} {p.currency}
                    </Text>
                    {p._pending ? <PendingPill /> : null}
                </YStack>
            </XStack>
            {p.description ? (
                <Text color='$textSecondary' numberOfLines={2}>
                    {p.description}
                </Text>
            ) : null}
            <Text color='$textSecondary' fontSize={12}>
                {p.photos?.length ?? 0} {t('petty.receipts')}
            </Text>
        </Card>
    );

    return (
        <YStack flex={1} bg='$background'>
            <SyncBanner />
            <XStack px='$3' py='$3' justifyContent='space-between' alignItems='center'>
                <Text color='$textSecondary'>{t('petty.total')}</Text>
                <Text color='$textPrimary' fontSize={22} fontWeight='bold'>
                    {fromMinorUnits(total)} {currency}
                </Text>
            </XStack>
            <FlatList
                data={rows}
                keyExtractor={(p) => p.id}
                renderItem={renderItem}
                refreshControl={<RefreshControl refreshing={isRefreshing} onRefresh={refresh} />}
                contentContainerStyle={{ paddingBottom: insets.bottom + 90, flexGrow: 1 }}
                ListEmptyComponent={<EmptyState text={t('petty.empty')} />}
            />
            <YStack position='absolute' right={16} bottom={insets.bottom + 16}>
                <Button circular size='$6' bg='$info' borderWidth={1} borderColor='$infoBorder' onPress={() => navigation.navigate('CreatePettyCash', { visitId })} accessibilityLabel={t('petty.new')}>
                    <Button.Icon>
                        <FontAwesomeIcon icon={faPlus} color={theme.infoText?.val} size={20} />
                    </Button.Icon>
                </Button>
            </YStack>
        </YStack>
    );
};

export default PettyCashListScreen;
