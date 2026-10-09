/**
 * Geofence Engine — Haversine Formula (matching App\Services\GeofenceService)
 * Earth radius = 6,371,000 meters
 */

const EARTH_RADIUS_METERS = 6371000;

function deg2rad(deg) {
  return deg * (Math.PI / 180);
}

function calculateDistance(lat1, lng1, lat2, lng2) {
  const lat1Rad = deg2rad(lat1);
  const lat2Rad = deg2rad(lat2);
  const lng1Rad = deg2rad(lng1);
  const lng2Rad = deg2rad(lng2);

  const deltaLat = lat2Rad - lat1Rad;
  const deltaLng = lng2Rad - lng1Rad;

  const a = Math.sin(deltaLat / 2) ** 2 +
            Math.cos(lat1Rad) * Math.cos(lat2Rad) * (Math.sin(deltaLng / 2) ** 2);

  const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));

  return EARTH_RADIUS_METERS * c;
}

function checkGeofence(empLat, empLng, branch) {
  const distance = calculateDistance(empLat, empLng, branch.lat, branch.lng);
  const roundedDist = Math.round(distance * 10) / 10;
  const isWithin = distance <= branch.radius;
  const excess = Math.max(0, Math.round(distance - branch.radius));

  return {
    within_geofence: isWithin,
    distance_meters: roundedDist,
    allowed_radius: branch.radius,
    excess_meters: excess,
    branch_name: branch.name,
    message: isWithin
      ? `Ubicación válida. Estás a ${roundedDist}m de ${branch.name}.`
      : `Fuera del área permitida (${roundedDist}m vs máx. ${branch.radius}m). Exceso: ${excess}m.`
  };
}

module.exports = {
  EARTH_RADIUS_METERS,
  calculateDistance,
  checkGeofence
};
