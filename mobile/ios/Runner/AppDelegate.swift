import CoreLocation
import Darwin
import MachO
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

    // Canal "tunesa/device_integrity": jailbreak, simulador, depurador y librerías inyectadas
    let integrity = FlutterMethodChannel(
      name: "tunesa/device_integrity",
      binaryMessenger: engineBridge.applicationRegistrar.messenger()
    )
    integrity.setMethodCallHandler { call, result in
      guard call.method == "check" else {
        result(FlutterMethodNotImplemented)
        return
      }
      result(DeviceIntegrity.check())
    }
  }
}

/// Revisiones locales (sin red). La app manda las señales al servidor y RRHH ve
/// la marcación como sospechosa; no bloquean el uso.
enum DeviceIntegrity {
  static func check() -> [String: Any] {
    #if targetEnvironment(simulator)
    let simulator = true
    #else
    let simulator = false
    #endif
    #if DEBUG
    let debugger = false  // en desarrollo el depurador es normal
    #else
    let debugger = isDebuggerAttached()
    #endif
    return [
      "rooted": !simulator && isJailbroken(),
      "emulator": simulator,
      "debugger": debugger,
      "hooked": hasInjectedLibraries(),
    ]
  }

  private static func isJailbroken() -> Bool {
    let paths = [
      "/Applications/Cydia.app", "/Applications/Sileo.app", "/Applications/Zebra.app",
      "/Library/MobileSubstrate/MobileSubstrate.dylib", "/bin/bash", "/usr/sbin/sshd", "/etc/apt",
      "/private/var/lib/apt/", "/usr/bin/ssh", "/var/jb", "/private/preboot/jb",
    ]
    if paths.contains(where: { FileManager.default.fileExists(atPath: $0) }) { return true }
    // Fuera del sandbox no se puede escribir en un teléfono sin jailbreak
    let probe = "/private/tunesa_jb_probe.txt"
    if (try? "x".write(toFile: probe, atomically: true, encoding: .utf8)) != nil {
      try? FileManager.default.removeItem(atPath: probe)
      return true
    }
    return false
  }

  private static func hasInjectedLibraries() -> Bool {
    let suspects = ["frida", "cynject", "libcycript", "mobilesubstrate", "substrate", "substitute", "libhooker", "ellekit"]
    for i in 0..<_dyld_image_count() {
      guard let name = _dyld_get_image_name(i) else { continue }
      let lower = String(cString: name).lowercased()
      if suspects.contains(where: { lower.contains($0) }) { return true }
    }
    return false
  }

  private static func isDebuggerAttached() -> Bool {
    var info = kinfo_proc()
    var size = MemoryLayout<kinfo_proc>.stride
    var mib: [Int32] = [CTL_KERN, KERN_PROC, KERN_PROC_PID, getpid()]
    let ok = sysctl(&mib, UInt32(mib.count), &info, &size, nil, 0) == 0
    return ok && (info.kp_proc.p_flag & P_TRACED) != 0
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
