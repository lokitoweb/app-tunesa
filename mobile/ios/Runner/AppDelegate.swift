import CoreLocation
import Flutter
import UIKit

@main
@objc class AppDelegate: FlutterAppDelegate, FlutterImplicitEngineDelegate {
  private var locationIntegrity: LocationIntegrityChecker?

  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  func didInitializeImplicitFlutterEngine(_ engineBridge: FlutterImplicitEngineBridge) {
    GeneratedPluginRegistrant.register(with: engineBridge.pluginRegistry)

    // Canal "tunesa/location_integrity": ¿la ubicación es simulada? (iOS 15+)
    let checker = LocationIntegrityChecker()
    let channel = FlutterMethodChannel(
      name: "tunesa/location_integrity",
      binaryMessenger: engineBridge.applicationRegistrar.messenger()
    )
    channel.setMethodCallHandler { call, result in
      guard call.method == "checkSimulated" else {
        result(FlutterMethodNotImplemented)
        return
      }
      checker.check(result: result)
    }
    locationIntegrity = checker
  }
}

/// Lectura puntual con CLLocationManager para leer `sourceInformation`
/// (iOS 15+; el mínimo de la app es 15.5). Responde siempre: sin permiso, sin datos, error o tiempo
/// agotado devuelve `simulated: false` (el GPS lo valida geolocator y el backend).
final class LocationIntegrityChecker: NSObject, CLLocationManagerDelegate {
  private var manager: CLLocationManager?
  private var pending: [FlutterResult] = []
  private var timeout: DispatchWorkItem?

  func check(result: @escaping FlutterResult) {
    pending.append(result)
    if manager != nil { return }  // ya hay una lectura en curso

    let manager = CLLocationManager()
    let status = manager.authorizationStatus
    guard status == .authorizedWhenInUse || status == .authorizedAlways else {
      finish(simulated: false, accessory: false)
      return
    }
    manager.delegate = self
    manager.desiredAccuracy = kCLLocationAccuracyBest
    self.manager = manager

    let work = DispatchWorkItem { [weak self] in self?.finish(simulated: false, accessory: false) }
    timeout = work
    DispatchQueue.main.asyncAfter(deadline: .now() + 5, execute: work)
    manager.requestLocation()
  }

  func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
    guard let location = locations.last else {
      finish(simulated: false, accessory: false)
      return
    }
    // sourceInformation existe desde iOS 15 (el mínimo de la app es 15.5).
    if let info = location.sourceInformation {
      finish(simulated: info.isSimulatedBySoftware, accessory: info.isProducedByAccessory)
    } else {
      finish(simulated: false, accessory: false)
    }
  }

  func locationManager(_ manager: CLLocationManager, didFailWithError error: Error) {
    finish(simulated: false, accessory: false)
  }

  private func finish(simulated: Bool, accessory: Bool) {
    timeout?.cancel()
    timeout = nil
    manager?.delegate = nil
    manager = nil
    let callbacks = pending
    pending = []
    for callback in callbacks {
      callback(["simulated": simulated, "accessory": accessory, "supported": true])
    }
  }
}
