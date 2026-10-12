import React from 'react';
import { Pressable } from 'react-native';
import { YStack, XStack, Text, Image } from 'tamagui';
import { FontAwesomeIcon } from '@fortawesome/react-native-fontawesome';
import { faCamera, faTimes, faCloudArrowUp } from '@fortawesome/free-solid-svg-icons';
import { useTheme } from 'tamagui';

export interface PhotoGridItem {
    key: string;
    /** missing uri renders an empty "tap to capture" slot (used for the suggested inspection angles) */
    uri?: string;
    label?: string;
    /** photo is still on this device waiting for upload */
    pending?: boolean;
}

interface Props {
    items: PhotoGridItem[];
    columns?: number;
    onPressItem?: (item: PhotoGridItem, index: number) => void;
    onRemove?: (item: PhotoGridItem, index: number) => void;
    /** show a trailing "add" tile */
    onAdd?: () => void;
    addLabel?: string;
    tileHeight?: number;
}

/** Thumbnail grid with optional empty slots, remove buttons and an upload-pending marker. */
const PhotoGrid = ({ items, columns = 3, onPressItem, onRemove, onAdd, addLabel, tileHeight = 96 }: Props) => {
    const theme = useTheme();
    const tiles: (PhotoGridItem | 'add')[] = onAdd ? [...items, 'add'] : items;
    const basis = `${100 / columns - 2}%` as any;
    return (
        <XStack flexWrap='wrap' gap='$2' testID='photo-grid'>
            {tiles.map((it, index) => {
                if (it === 'add') {
                    return (
                        <Pressable key='__add' onPress={onAdd} style={{ width: basis }} accessibilityRole='button' accessibilityLabel={addLabel}>
                            <YStack height={tileHeight} borderWidth={1} borderStyle='dashed' borderColor='$borderColor' borderRadius='$4' alignItems='center' justifyContent='center' bg='$surface' gap='$1'>
                                <FontAwesomeIcon icon={faCamera} color={theme.textSecondary?.val} size={20} />
                                {addLabel ? (
                                    <Text color='$textSecondary' fontSize={12} textAlign='center' numberOfLines={2} px='$1'>
                                        {addLabel}
                                    </Text>
                                ) : null}
                            </YStack>
                        </Pressable>
                    );
                }
                const hasPhoto = !!it.uri;
                return (
                    <Pressable key={it.key} onPress={() => onPressItem?.(it, index)} style={{ width: basis }}>
                        <YStack height={tileHeight} borderRadius='$4' overflow='hidden' borderWidth={1} borderColor={hasPhoto ? '$borderColor' : '$warningBorder'} borderStyle={hasPhoto ? 'solid' : 'dashed'} bg='$surface' alignItems='center' justifyContent='center'>
                            {hasPhoto ? (
                                <Image source={{ uri: it.uri }} width='100%' height='100%' resizeMode='cover' />
                            ) : (
                                <FontAwesomeIcon icon={faCamera} color={theme.textSecondary?.val} size={22} />
                            )}
                            {it.label ? (
                                <YStack position='absolute' bottom={0} left={0} right={0} bg='rgba(0,0,0,0.55)' py={2} px='$1'>
                                    <Text color='white' fontSize={11} textAlign='center' numberOfLines={1}>
                                        {it.label}
                                    </Text>
                                </YStack>
                            ) : null}
                            {hasPhoto && it.pending ? (
                                <YStack position='absolute' top={4} left={4} bg='rgba(0,0,0,0.55)' borderRadius={10} p={4}>
                                    <FontAwesomeIcon icon={faCloudArrowUp} color='white' size={11} />
                                </YStack>
                            ) : null}
                            {hasPhoto && onRemove ? (
                                <Pressable onPress={() => onRemove(it, index)} hitSlop={8} style={{ position: 'absolute', top: 4, right: 4 }} accessibilityLabel='remove photo'>
                                    <YStack bg='rgba(0,0,0,0.65)' borderRadius={11} width={22} height={22} alignItems='center' justifyContent='center'>
                                        <FontAwesomeIcon icon={faTimes} color='white' size={12} />
                                    </YStack>
                                </Pressable>
                            ) : null}
                        </YStack>
                    </Pressable>
                );
            })}
        </XStack>
    );
};

export default PhotoGrid;
