// Ambient stand-ins for native / UI libraries that cannot be installed in this sandbox. Everything is `any`,
// so `tsc` here verifies OUR code (imports, types, props we own, JSON), not those libraries' prop typings.
declare module 'tamagui' {
    export const YStack: any, XStack: any, Text: any, Button: any, Spinner: any, Image: any, Input: any;
    export const useTheme: () => any;
}
declare module '@fortawesome/react-native-fontawesome' { export const FontAwesomeIcon: any; }
declare module '@fortawesome/free-solid-svg-icons' {
    export const faCamera: any, faTimes: any, faCloudArrowUp: any, faPlus: any, faWallet: any, faGaugeHigh: any;
}
declare module '@gorhom/portal' { export const PortalHost: any; }
declare module 'react-native-safe-area-context' { export const useSafeAreaInsets: () => { top: number; bottom: number; left: number; right: number }; }
declare module 'react-native-fs' { const RNFS: any; export default RNFS; }
declare module '@bam.tech/react-native-image-resizer' { const R: any; export default R; }
