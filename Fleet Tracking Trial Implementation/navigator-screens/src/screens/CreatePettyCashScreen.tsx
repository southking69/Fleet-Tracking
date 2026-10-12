import React, { useCallback, useState } from 'react';
import { Alert, ScrollView, TextInput } from 'react-native';
import { useNavigation, useRoute } from '@react-navigation/native';
import { YStack, Text, useTheme } from 'tamagui';
import { PortalHost } from '@gorhom/portal';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useAuth } from '../contexts/AuthContext';
import { useServiceVisits } from '../contexts/ServiceVisitContext';
import { useServiceT } from '../translations';
import BottomSheetSelect from '../components/BottomSheetSelect';
import MoneyInput from '../components/MoneyInput';
import CaptureModal from '../components/CaptureModal';
import PhotoGrid from '../components/PhotoGrid';
import { FieldLabel, SaveBar, SyncBanner } from '../components/ServiceUi';
import { fromMinorUnits, toMinorUnits } from '../services/visitModel';

const PORTAL = 'CreatePettyCashPortal';

const CreatePettyCashScreen = () => {
    const navigation = useNavigation<any>();
    const { params } = useRoute<any>();
    const theme = useTheme();
    const insets = useSafeAreaInsets();
    const { driver } = useAuth();
    const { t } = useServiceT();
    const { addPettyCash, lists, getVisit } = useServiceVisits();
    const visit = params?.visitId ? getVisit(params.visitId) : undefined;

    const [category, setCategory] = useState('');
    const [money, setMoney] = useState<{ value: string; currency: string } | null>(null);
    const [description, setDescription] = useState('');
    const [receipts, setReceipts] = useState<string[]>([]);
    const [capturing, setCapturing] = useState(false);
    const [sheetOpen, setSheetOpen] = useState(false);
    const [busy, setBusy] = useState(false);

    // MoneyInput calls onChange from an effect keyed on [onChange]; keep it referentially stable.
    const onMoney = useCallback((m: { value: string; currency: string }) => setMoney(m), []);

    const minor = money ? toMinorUnits(money.value) : null;
    const valid = !!category && minor != null && minor > 0;

    const onSave = useCallback(async () => {
        if (!valid || minor == null) return;
        setBusy(true);
        try {
            await addPettyCash(
                { category, amount: minor, currency: money?.currency || 'SAR', description, paid_by: (driver as any)?.name, plate: visit?.plate ?? params?.plate },
                receipts,
                visit?.id ?? null
            );
            navigation.goBack();
        } catch (e: any) {
            Alert.alert(t('common.error'), String(e?.message || e));
        } finally {
            setBusy(false);
        }
    }, [valid, minor, money, category, description, driver, visit, params, receipts, addPettyCash, navigation, t]);

    if (capturing)
        return (
            <CaptureModal
                onDone={(p) => {
                    setReceipts((prev) => [...prev, ...p.map((x) => x.uri)]);
                    setCapturing(false);
                }}
                onClose={() => setCapturing(false)}
            />
        );

    return (
        <YStack flex={1} bg='$background'>
            <SyncBanner />
            <ScrollView contentContainerStyle={{ padding: 12, gap: 14, paddingBottom: 24 }} keyboardShouldPersistTaps='handled' scrollEnabled={!sheetOpen}>
                {visit ? (
                    <Text color='$textSecondary'>
                        {t('petty.forVisit')}: {visit.plate}
                    </Text>
                ) : null}

                <YStack gap='$2'>
                    <FieldLabel>{t('petty.category')}</FieldLabel>
                    <BottomSheetSelect value={category} options={lists.expenseCats} onChange={setCategory} title={t('petty.category')} placeholder={t('petty.selectCategory')} portalHost={PORTAL} snapTo='90%' onBottomSheetPositionChanged={setSheetOpen} />
                </YStack>

                <YStack gap='$2'>
                    <FieldLabel>{t('petty.amount')}</FieldLabel>
                    <MoneyInput value={money?.value} defaultCurrency={lists.currency || 'SAR'} onChange={onMoney} placeholder={t('petty.amountPlaceholder')} portalHost={PORTAL} onBottomSheetPositionChanged={setSheetOpen} />
                    {/* MoneyInput never reports a cleared field, so always show what will really be saved */}
                    {minor != null ? (
                        <Text color='$textSecondary' fontSize={13} px='$1'>
                            {t('petty.willSave', { amount: fromMinorUnits(minor), currency: money?.currency || 'SAR' })}
                        </Text>
                    ) : null}
                </YStack>

                <YStack gap='$2'>
                    <FieldLabel>{t('petty.description')}</FieldLabel>
                    <TextInput
                        value={description}
                        onChangeText={setDescription}
                        multiline
                        numberOfLines={3}
                        placeholder={t('petty.descriptionPlaceholder')}
                        placeholderTextColor={theme.textSecondary?.val}
                        style={{ minHeight: 80, borderWidth: 1, borderColor: theme.borderColor?.val, borderRadius: 12, padding: 12, color: theme.textPrimary?.val, backgroundColor: theme.surface?.val, textAlignVertical: 'top' }}
                    />
                </YStack>

                <YStack gap='$2'>
                    <FieldLabel>{t('petty.receiptsLabel')}</FieldLabel>
                    <PhotoGrid
                        items={receipts.map((uri, i) => ({ key: `${i}-${uri}`, uri, pending: true }))}
                        onRemove={(_it, idx) => setReceipts((prev) => prev.filter((_, i) => i !== idx))}
                        onAdd={() => setCapturing(true)}
                        addLabel={t('petty.addReceipt')}
                    />
                </YStack>
            </ScrollView>
            <YStack pb={insets.bottom}>
                <SaveBar onPress={onSave} disabled={!valid} busy={busy} label={t('petty.save')} />
            </YStack>
            <PortalHost name={PORTAL} />
        </YStack>
    );
};

export default CreatePettyCashScreen;
