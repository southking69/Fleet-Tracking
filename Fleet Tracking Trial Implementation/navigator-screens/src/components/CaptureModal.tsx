import React from 'react';
import { Pressable } from 'react-native';
import { YStack, Text } from 'tamagui';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import CameraCapture from './CameraCapture';
import { useServiceT } from '../translations';

interface Props {
    onDone: (photos: { uri: string }[]) => void;
    onClose: () => void;
}

/** Full-screen wrapper around the app's existing CameraCapture, adding a close button. */
const CaptureModal = ({ onDone, onClose }: Props) => {
    const insets = useSafeAreaInsets();
    const { t } = useServiceT();
    return (
        <YStack flex={1} bg='$background'>
            <CameraCapture onDone={onDone} />
            <Pressable onPress={onClose} hitSlop={10} style={{ position: 'absolute', top: insets.top + 8, left: 12 }} accessibilityRole='button' accessibilityLabel={t('common.cancel')}>
                <YStack bg='rgba(0,0,0,0.6)' borderRadius='$5' px='$3' py='$2'>
                    <Text color='white' fontWeight='bold'>
                        {t('common.cancel')}
                    </Text>
                </YStack>
            </Pressable>
        </YStack>
    );
};

export default CaptureModal;
