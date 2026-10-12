import React, { useCallback, useMemo, useState } from 'react';
import { Alert, ScrollView } from 'react-native';
import { useNavigation, useRoute } from '@react-navigation/native';
import { YStack, XStack, Text } from 'tamagui';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useServiceVisits } from '../contexts/ServiceVisitContext';
import { useServiceT } from '../translations';
import CaptureModal from '../components/CaptureModal';
import PhotoGrid, { PhotoGridItem } from '../components/PhotoGrid';
import { EmptyState, SaveBar, SyncBanner } from '../components/ServiceUi';
import { INSPECTION_ANGLES } from '../services/visitModel';

interface Shot {
    id: string;
    uri: string;
    angle?: string; // undefined = extra photo
}

/**
 * Pickup / return inspection. The 8 suggested angles are shown as slots; tap a slot to capture it. The
 * camera can take several photos at once: they fill the tapped slot and then the next empty ones in
 * the suggested order, any surplus becomes "extra" photos. Photos are resized (1000 px, JPEG q0.72) and
 * copied to durable storage only on submit, then uploaded by the offline queue, separately from the
 * record write.
 */
let shotSeq = 0;
const nid = () => `shot-${Date.now().toString(36)}-${++shotSeq}`;

const InspectionScreen = () => {
    const navigation = useNavigation<any>();
    const { params } = useRoute<any>();
    const insets = useSafeAreaInsets();
    const { t } = useServiceT();
    const { getVisit, submitInspection } = useServiceVisits();
    const stage: 'pickup' | 'return' = params.stage === 'return' ? 'return' : 'pickup';
    const visit = getVisit(params.visitId);
    const [shots, setShots] = useState<Shot[]>([]);
    const [capturingFor, setCapturingFor] = useState<string | 'extra' | null>(null);
    const [busy, setBusy] = useState(false);

    const already = ((visit?.inspection_photos as any)?.[stage] ?? []) as { uri: string; angle?: string; local?: boolean }[];

    const handleDone = useCallback(
        (photos: { uri: string }[]) => {
            const target = capturingFor;
            setCapturingFor(null);
            if (!photos.length || !target) return;
            setShots((prev) => {
                const next = prev.slice();
                const taken = new Set(next.filter((s) => s.angle).map((s) => s.angle as string));
                already.forEach((a) => a.angle && taken.add(a.angle));
                const queue = photos.slice();
                if (target !== 'extra') {
                    // tapped slot first (replace its photo if it already had one), then following empty slots
                    const startIdx = INSPECTION_ANGLES.indexOf(target as any);
                    const order = [...INSPECTION_ANGLES.slice(startIdx), ...INSPECTION_ANGLES.slice(0, startIdx)];
                    for (const angle of order) {
                        if (!queue.length) break;
                        if (angle !== target && taken.has(angle)) continue;
                        const idx = next.findIndex((s) => s.angle === angle);
                        const p = queue.shift()!;
                        if (idx >= 0) next[idx] = { id: next[idx].id, uri: p.uri, angle };
                        else next.push({ id: nid(), uri: p.uri, angle });
                        taken.add(angle);
                    }
                }
                queue.forEach((p) => next.push({ id: nid(), uri: p.uri }));
                return next;
            });
        },
        [capturingFor, already]
    );

    const items: PhotoGridItem[] = useMemo(() => {
        const out: PhotoGridItem[] = INSPECTION_ANGLES.map((angle) => {
            const s = shots.find((x) => x.angle === angle);
            const prev = already.find((x) => x.angle === angle);
            return { key: angle, uri: s?.uri ?? prev?.uri, label: t(`inspection.angle.${angle}`), pending: !!s || !!prev?.local };
        });
        shots.filter((s) => !s.angle).forEach((s) => out.push({ key: s.id, uri: s.uri, label: t('inspection.extra'), pending: true }));
        return out;
    }, [shots, already, t]);

    const missing = INSPECTION_ANGLES.filter((a) => !items.find((i) => i.key === a)?.uri);

    const doSubmit = useCallback(async () => {
        if (!visit) return;
        setBusy(true);
        try {
            await submitInspection(visit.id, stage, shots.map(({ uri, angle }) => ({ uri, angle })));
            navigation.goBack();
        } catch (e: any) {
            Alert.alert(t('common.error'), String(e?.message || e));
        } finally {
            setBusy(false);
        }
    }, [visit, submitInspection, stage, shots, navigation, t]);

    const onSubmit = useCallback(() => {
        // The 8 angles are SUGGESTED, not mandatory: warn about gaps but never block the driver.
        if (missing.length && shots.length) {
            Alert.alert(t('inspection.missingTitle'), t('inspection.missingBody', { n: missing.length }), [
                { text: t('common.cancel'), style: 'cancel' },
                { text: t('inspection.submitAnyway'), onPress: doSubmit },
            ]);
        } else {
            void doSubmit();
        }
    }, [missing.length, shots.length, doSubmit, t]);

    if (!visit) return <EmptyState text={t('visit.notFound')} />;

    if (capturingFor) return <CaptureModal onDone={handleDone} onClose={() => setCapturingFor(null)} />;

    return (
        <YStack flex={1} bg='$background'>
            <SyncBanner />
            <ScrollView contentContainerStyle={{ padding: 12, gap: 12, paddingBottom: 24 }}>
                <YStack gap='$1'>
                    <Text color='$textPrimary' fontSize={22} fontWeight='bold'>
                        {t(`inspection.${stage}`)} {'·'} {visit.plate}
                    </Text>
                    <Text color='$textSecondary'>{t('inspection.hint')}</Text>
                </YStack>
                <PhotoGrid
                    items={items}
                    columns={3}
                    tileHeight={104}
                    onPressItem={(it) => (INSPECTION_ANGLES as readonly string[]).includes(it.key) && setCapturingFor(it.key)}
                    onRemove={(it) => setShots((prev) => prev.filter((s) => (s.angle ? s.angle !== it.key : s.id !== it.key)))}
                    onAdd={() => setCapturingFor('extra')}
                    addLabel={t('inspection.addExtra')}
                />
                <XStack justifyContent='space-between'>
                    <Text color='$textSecondary'>{t('inspection.progress', { n: INSPECTION_ANGLES.length - missing.length, total: INSPECTION_ANGLES.length })}</Text>
                    <Text color='$textSecondary'>{t('inspection.toUpload', { n: shots.length })}</Text>
                </XStack>
            </ScrollView>
            <YStack pb={insets.bottom}>
                <SaveBar onPress={onSubmit} busy={busy} disabled={!shots.length} label={t('inspection.submit')} />
            </YStack>
        </YStack>
    );
};

export default InspectionScreen;
