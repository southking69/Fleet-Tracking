import React from 'react';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { Text } from 'tamagui';
import { faTimes } from '@fortawesome/free-solid-svg-icons';
import { getTheme } from '../../utils';
import HeaderButton from '../../components/HeaderButton';
import { useServiceT } from '../../translations';
import { useServiceVisits } from '../../contexts/ServiceVisitContext';
import ServiceVisitListScreen from '../../screens/ServiceVisitListScreen';
import ServiceVisitScreen from '../../screens/ServiceVisitScreen';
import VendorStopScreen from '../../screens/VendorStopScreen';
import ServiceJobScreen from '../../screens/ServiceJobScreen';
import InspectionScreen from '../../screens/InspectionScreen';
import CreateDamageScreen from '../../screens/CreateDamageScreen';
import PettyCashListScreen from '../../screens/PettyCashListScreen';
import CreatePettyCashScreen from '../../screens/CreatePettyCashScreen';
import VehicleDueScreen from '../../screens/VehicleDueScreen';

const headerStyle = () => ({ backgroundColor: getTheme('background'), headerTintColor: getTheme('borderColor') });

const Title = ({ children }: { children: React.ReactNode }) => (
    <Text color='$textPrimary' fontSize={20} fontWeight='bold' numberOfLines={1}>
        {children}
    </Text>
);

/** Header factory: translated title on the leading side (same look as the app's other stacks), optional close button for modals. */
function header(titleKey: string, opts: { modal?: boolean; plate?: boolean } = {}) {
    return ({ route, navigation }: any) => {
        const { t } = useServiceT();
        const { getVisit } = useServiceVisits();
        const plate = opts.plate && route.params?.visitId ? getVisit(route.params.visitId)?.plate : undefined;
        return {
            headerTitle: '',
            headerShadowVisible: false,
            ...(opts.modal ? { presentation: 'modal' as const } : {}),
            headerLeft: () => <Title>{plate ? `${t(titleKey)} · ${plate}` : t(titleKey)}</Title>,
            headerRight: opts.modal ? () => <HeaderButton icon={faTimes} onPress={() => navigation.goBack()} /> : undefined,
            headerStyle: headerStyle(),
        };
    };
}

export const ServiceVisitList = { screen: ServiceVisitListScreen, options: header('nav.visits') };
export const ServiceVisit = { screen: ServiceVisitScreen, options: header('nav.visit', { plate: true }) };
export const VendorStop = { screen: VendorStopScreen, options: header('nav.vendorStop', { plate: true }) };
export const ServiceJob = { screen: ServiceJobScreen, options: header('nav.job', { modal: true, plate: true }) };
export const Inspection = { screen: InspectionScreen, options: header('nav.inspection', { modal: true }) };
export const CreateDamage = { screen: CreateDamageScreen, options: header('nav.damage', { modal: true, plate: true }) };
export const PettyCashList = { screen: PettyCashListScreen, options: header('nav.pettyCash') };
export const CreatePettyCash = { screen: CreatePettyCashScreen, options: header('nav.newPettyCash', { modal: true }) };
export const VehicleDue = { screen: VehicleDueScreen, options: header('nav.vehiclesDue') };

/** Screens map, to spread into any native-stack `screens`. */
const ServiceStack = {
    ServiceVisitList,
    ServiceVisit,
    VendorStop,
    ServiceJob,
    Inspection,
    CreateDamage,
    PettyCashList,
    CreatePettyCash,
    VehicleDue,
};

/** Ready-made tab content: <DriverNavigator> gets one new tab whose component is this stack (see PATCHES.md). */
export const DriverServiceTab = createNativeStackNavigator({
    initialRouteName: 'ServiceVisitList',
    screens: ServiceStack,
});

export default ServiceStack;
