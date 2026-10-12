# PATCHES: the few lines to change in a fork of fleetbase/navigator-app

Everything under `src/` here is **new files** (drop-in, no overwrites). Existing files need only the edits below.
Paths and line shapes were checked against a fresh clone of `fleetbase/navigator-app` (`main`, RN 0.86, React 19, react-navigation 7 *static* API, tamagui 1.125).

New files to copy (same relative paths):

```
src/services/{offlineQueue,visitModel,taskFactory,fleetbaseTransport,mmkvStorage,imageResize,photoStore}.ts
src/contexts/ServiceVisitContext.tsx
src/hooks/use-service-bridge.ts
src/navigation/stacks/ServiceStack.tsx
src/components/{PhotoGrid,StageSummary,DueBadge,ServiceUi,CaptureModal}.tsx
src/screens/{ServiceVisitList,ServiceVisit,VendorStop,ServiceJob,Inspection,CreateDamage,PettyCashList,CreatePettyCash,VehicleDue}Screen.tsx
src/translations/{index.ts,translate.ts,serviceTracker.en.json,serviceTracker.ar.json}
__tests__/{offlineQueue,visitModel,taskFactory,translations}.test.ts + __tests__/helpers.ts + __tests__/fixtures/calc-parity.json
```

Reused from the existing app **by import path, unmodified**: `components/CameraCapture`, `components/MoneyInput`, `components/OdometerNumber`,
`components/BottomSheetSelect`, `components/HeaderButton`, `hooks/use-fleetbase`, `hooks/use-storage` (MMKV `storage`), `contexts/AuthContext`,
`contexts/LanguageContext`, `utils` (`getTheme`), and the packages `@bam.tech/react-native-image-resizer`, `react-native-fs`, `@gorhom/portal`.

## 0. Dependency (one, optional but recommended)

```
yarn add @react-native-community/netinfo
```
Only `src/legacy` uses it today; it is not in `package.json`. Without it the queue still flushes on app foreground, on every enqueue and every 30 s,
but it will not react the instant connectivity returns. The context `require()`s it inside try/catch, so the app builds either way.

## 1. `App.tsx`: mount the provider (2 lines + import)

```diff
+import { ServiceVisitProvider } from './src/contexts/ServiceVisitContext';
 ...
                                                             <OrderManagerProvider>
+                                                              <ServiceVisitProvider>
                                                                 <AppNavigator />
+                                                              </ServiceVisitProvider>
                                                                 <Toasts extraInsets={{ bottom: 80 }} />
```
It must be inside `AuthProvider` + `ConfigProvider` (it calls `useFleetbase()`), and above `AppNavigator` (the stack headers read the visit plate).

## 2. `src/navigation/stacks/CoreStack.tsx` (3 lines)

```diff
+import ServiceStack from './ServiceStack';
 ...
 const CoreStack = {
     Boot,
     Test,
     LocationPermission,
     InstanceLink,
+    ...ServiceStack,   // ServiceVisitList, ServiceVisit, VendorStop, ServiceJob, Inspection, CreateDamage, PettyCashList, CreatePettyCash, VehicleDue
 };
+export { ServiceStack };
```
Use this if you want the screens reachable from the **root** stack (e.g. `...CoreStack` spread in `AppNavigator.tsx`, or deep links). If you use the tab in section 3, do **not** also register the same names at root (duplicate route names are confusing for `navigate`); pick one. Section 3 is the recommended entry point.

## 3. Tab bar: `src/navigation/DriverNavigator.tsx` (5 small edits)

```diff
+import { DriverServiceTab } from './stacks/ServiceStack';
+import { faWrench } from '@fortawesome/free-solid-svg-icons';          // add to the existing faXxx import list
 const importedIconsMap = { ..., faFlag,
+    faWrench,
 };
 function createTabScreens() {
-    const tabs = toArray(navigatorConfig('driverNavigator.tabs', 'DriverDashboardTab,DriverTaskTab,DriverReportTab,DriverChatTab,DriverAccountTab'));
+    const tabs = toArray(navigatorConfig('driverNavigator.tabs', 'DriverDashboardTab,DriverTaskTab,DriverServiceTab,DriverReportTab,DriverChatTab,DriverAccountTab'));
     const screens = {
         ...
+        DriverServiceTab: {
+            screen: DriverServiceTab,
+            options: { tabBarLabel: config('DRIVER_SERVICE_TAB_LABEL', 'Service'), headerShown: false },
+        },
 function getDefaultTabIcon(routeName) {
     ...
+        case 'DriverServiceTab':
+            icon = faWrench;
+            break;
```
If your deployment sets `driverNavigator.tabs` in `navigator.config.ts` / env, add `DriverServiceTab` there instead of changing the default string. The stack already draws its own headers, hence `headerShown: false` on the tab (same as the stock tabs).

## 4. `src/components/IssueForm.tsx` (record a damage straight from an issue; ~6 lines)

```diff
+import useServiceBridge from '../hooks/use-service-bridge';
 ...
 const IssueForm = (...) => {
+    const { available: stAvailable, activeVisit } = useServiceBridge();
 ...
 // just above the closing </YStack> of the form body (same level as the other px='$3' blocks)
+                {stAvailable && activeVisit ? (
+                    <YStack px='$3'>
+                        <Button onPress={() => navigation.navigate('DriverServiceTab', { screen: 'CreateDamage', params: { visitId: activeVisit.id } })}>
+                            <Button.Text>Record as damage on {activeVisit.plate}</Button.Text>
+                        </Button>
+                    </YStack>
+                ) : null}
```
`useServiceBridge` returns `available: false` when the provider is not mounted, so the form is unchanged for fleets that do not use Service Tracker.

## 5. `src/components/FuelReportForm.tsx` (also log fuel as petty cash; ~8 lines)

```diff
+import useServiceBridge from '../hooks/use-service-bridge';
+import { Pressable } from 'react-native';
 ...
+    const { available: stAvailable, activeVisit, logFuelAsPettyCash } = useServiceBridge();
+    const [alsoPetty, setAlsoPetty] = useState(false);
 ...
     const handleSubmit = useCallback(() => {
         if (onSubmit && isValid) {
             ...
             onSubmit(formattedFuelReport);
+            if (alsoPetty) logFuelAsPettyCash(String(fuelReport.amount), fuelReport.currency, `Fuel ${fuelReport.volume ?? ''} ${fuelReport.metric_unit ?? ''}`.trim());
         }
-    }, [onSubmit, isValid, fuelReport]);
+    }, [onSubmit, isValid, fuelReport, alsoPetty, logFuelAsPettyCash]);
 ...
 // below the Cost block
+                    {stAvailable && activeVisit ? (
+                        <Pressable onPress={() => setAlsoPetty((v) => !v)}>
+                            <Text color='$textPrimary' px='$4'>{alsoPetty ? '☑' : '☐'} Also log as petty cash on {activeVisit.plate}</Text>
+                        </Pressable>
+                    ) : null}
```
The petty-cash write goes through the same offline queue (idempotent `client_uuid`).

## 6. Existing-code issues found while mirroring the app (NOT fixed here; your call)

* `src/components/OdometerNumber.tsx`: `value = value ? 0 : value;` zeroes every truthy value, so the odometer always renders `0`. The new screens use the component as asked
  and will show `0` until it is changed to `value = value > 0 ? value : 0;`.
* `src/navigation/DriverNavigator.tsx` `tabBarLabelStyle` reads `focued` (typo for `focused`).
* `MoneyInput` only calls `onChange` when the amount is non-empty (it never reports a cleared field), so `CreatePettyCashScreen` keeps the last non-empty amount in state if the user wipes the input. The save button is gated on that value; the amount is re-read on the next edit.

## 7. Server contract this app relies on (beyond what SPEC.md spells out)

See `NOTES.md` section "Assumptions on the REST API".
