import React, { useCallback, useState } from 'react';
import { Alert, ScrollView, TextInput } from 'react-native';
import { useNavigation, useRoute } from '@react-navigation/native';
import { YStack, Text, useTheme } from 'tamagui';
import { PortalHost } from '@gorhom/portal';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useServiceVisits } from '../contexts/ServiceVisitContext';
import { useServiceT } from '../translations';
import BottomSheetSelect from '../components/BottomSheetSelect';
import CaptureModal from '../components/CaptureModal';
import PhotoGrid from '../components/PhotoGrid';
import { ChipSelect, EmptyState, FieldLabel, SaveBar, SyncBanner } from '../components/ServiceUi';
import { Condition, Severity, StageName } from '../services/visitModel';

const STAGES: StageName[] = ['Pickup inspection', 'Return inspection', 'Vendor reported'];
const CONDITIONS: Condition[] = ['Pre-existing', 'New'];
const PORTAL = 'CreateDamagePortal';

const CreateDamageScreen = () => {
    const navigation = useNavigation<any>();
    const { params } = useRoute<any>();
    const theme = useTheme();
    const insets = useSafeAreaInsets();
    const { t, tv } = useServiceT();
    const { getVisit, addDamage, lists } = useServiceVisits();
    const visit = getVisit(params.visitId);

    const [stage, setStage] = useState<StageName>(params.stage ?? (visit?.picked_up_at && !visit?.return_inspected && visit?.pickup_inspected ? 'Return inspection' : 'Pickup inspection'));
    const [location, setLocation] = useState('');
    const [type, setType] = useState('');
    const [severity, setSeverity] = useState<Severity | ''>('');
    const [condition, setCondition] = useState<Condition | ''>('');
    const [notified, setNotified] = useState<'Yes' | 'No'>('No');
    const [notes, setNotes] = useState('');
    const [photos, setPhotos] = useState<string[]>([]);
    const [capturing, setCapturing] = useState(false);
    const [sheetOpen, setSheetOpen] = useState(false);
    const [busy, setBusy] = useState(false);

    const valid = !!location && !!type && !!severity && !!condition;

    const onSave = useCallback(async () => {
        if (!visit || !valid) return;
        setBusy(true);
        try {
            await addDamage(visit.id, { stage, location, damage_type: type, severity: severity as Severity, condition: condition as Condition, client_notified: notified, notes }, photos);
            navigation.goBack();
        } catch (e: any) {
            Alert.alert(t('common.error'), String(e?.message || e));
        } finally {
            setBusy(false);
        }
    }, [visit, valid, addDamage, stage, location, type, severity, condition, notified, notes, photos, navigation, t]);

    if (!visit) return <EmptyState text={t('visit.notFound')} />;
    if (capturing)
        return (
            <CaptureModal
                onDone={(p) => {
                    setPhotos((prev) => [...prev, ...p.map((x) => x.uri)]);
                    setCapturing(false);
                }}
                onClose={() => setCapturing(false)}
            />
        );

    return (
        <YStack flex={1} bg='$background'>
            <SyncBanner />
            <ScrollView contentContainerStyle={{ padding: 12, gap: 14, paddingBottom: 24 }} keyboardShouldPersistTaps='handled' scrollEnabled={!sheetOpen}>
                <Text color='$textSecondary'>{visit.plate}</Text>

                <YStack gap='$2'>
                    <FieldLabel>{t('damage.stageLabel')}</FieldLabel>
                    <ChipSelect options={STAGES} value={stage} onChange={setStage} label={(v) => tv('damage.stage', v)} />
                </YStack>

                <YStack gap='$2'>
                    <FieldLabel>{t('damage.location')}</FieldLabel>
                    <BottomSheetSelect value={location} options={lists.damageLocations} onChange={setLocation} title={t('damage.location')} placeholder={t('damage.selectLocation')} portalHost={PORTAL} snapTo='90%' onBottomSheetPositionChanged={setSheetOpen} />
                </YStack>

                <YStack gap='$2'>
                    <FieldLabel>{t('damage.type')}</FieldLabel>
                    <BottomSheetSelect value={type} options={lists.damageTypes} onChange={setType} title={t('damage.type')} placeholder={t('damage.selectType')} portalHost={PORTAL} snapTo='90%' onBottomSheetPositionChanged={setSheetOpen} />
                </YStack>

                <YStack gap='$2'>
                    <FieldLabel>{t('damage.severityLabel')}</FieldLabel>
                    <ChipSelect options={lists.severities as Severity[]} value={severity} onChange={setSeverity} label={(v) => tv('damage.severity', v)} />
                </YStack>

                <YStack gap='$2'>
                    <FieldLabel>{t('damage.conditionLabel')}</FieldLabel>
                    <ChipSelect options={CONDITIONS} value={condition} onChange={setCondition} label={(v) => tv('damage.condition', v)} />
                    <Text color='$textSecondary' fontSize={12}>
                        {t('damage.conditionHint')}
                    </Text>
                </YStack>

                <YStack gap='$2'>
                    <FieldLabel>{t('damage.clientNotified')}</FieldLabel>
                    <ChipSelect options={['Yes', 'No'] as const} value={notified} onChange={setNotified} label={(v) => t(v === 'Yes' ? 'common.yes' : 'common.no')} />
                </YStack>

                <YStack gap='$2'>
                    <FieldLabel>{t('common.photos')}</FieldLabel>
                    <PhotoGrid
                        items={photos.map((uri, i) => ({ key: `${i}-${uri}`, uri, pending: true }))}
                        onRemove={(_it, idx) => setPhotos((prev) => prev.filter((_, i) => i !== idx))}
                        onAdd={() => setCapturing(true)}
                        addLabel={t('damage.addPhotos')}
                    />
                </YStack>

                <YStack gap='$2'>
                    <FieldLabel>{t('common.notes')}</FieldLabel>
                    <TextInput
                        value={notes}
                        onChangeText={setNotes}
                        multiline
                        numberOfLines={3}
                        placeholder={t('common.notes')}
                        placeholderTextColor={theme.textSecondary?.val}
                        style={{ minHeight: 80, borderWidth: 1, borderColor: theme.borderColor?.val, borderRadius: 12, padding: 12, color: theme.textPrimary?.val, backgroundColor: theme.surface?.val, textAlignVertical: 'top' }}
                    />
                </YStack>
            </ScrollView>
            <YStack pb={insets.bottom}>
                <SaveBar onPress={onSave} disabled={!valid} busy={busy} label={t('damage.save')} />
            </YStack>
            <PortalHost name={PORTAL} />
        </YStack>
    );
};

export default CreateDamageScreen;
