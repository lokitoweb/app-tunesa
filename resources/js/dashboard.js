/**
 * TuneSA Web Dashboard — Real-time Geofence & Attendance Monitor
 */

class TuneSADashboard {
    constructor(config) {
        this.mapElementId = config.mapElementId || 'liveMap';
        this.streamUrl = config.streamUrl;
        this.branches = config.branches || [];
        this.refreshInterval = config.refreshInterval || 15000;
        this.map = null;
        this.employeeMarkers = [];
        this.branchCircles = [];
    }

    init() {
        this.initMap();
        this.renderBranches();
        this.startLivePolling();
    }

    initMap() {
        const defaultLat = this.branches.length > 0 ? parseFloat(this.branches[0].latitude) : -12.046374;
        const defaultLng = this.branches.length > 0 ? parseFloat(this.branches[0].longitude) : -77.042793;

        this.map = L.map(this.mapElementId, {
            zoomControl: true,
            scrollWheelZoom: true
        }).setView([defaultLat, defaultLng], 14);

        // CartoDB Dark Matter tiles
        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
            attribution: '&copy; OpenStreetMap contributors &copy; CARTO',
            maxZoom: 19
        }).addTo(this.map);
    }

    renderBranches() {
        this.branches.forEach(branch => {
            const lat = parseFloat(branch.latitude);
            const lng = parseFloat(branch.longitude);
            const radius = parseInt(branch.allowed_radius_meters) || 50;

            const circle = L.circle([lat, lng], {
                color: '#6366f1',
                fillColor: '#6366f1',
                fillOpacity: 0.15,
                weight: 2,
                radius: radius
            }).addTo(this.map);

            circle.bindPopup(`
                <div style="color: #0f172a; padding: 4px;">
                    <div style="font-weight: 700; font-size: 13px;">📍 ${branch.name}</div>
                    <div style="font-size: 11px; color: #64748b;">${branch.address}</div>
                    <div style="font-size: 11px; margin-top: 4px; color: #4338ca; font-weight: 600;">Radio Geocerca: ${radius}m</div>
                </div>
            `);

            this.branchCircles.push(circle);
            L.marker([lat, lng]).addTo(this.map);
        });
    }

    startLivePolling() {
        this.fetchUpdates();
        setInterval(() => this.fetchUpdates(), this.refreshInterval);
    }

    async fetchUpdates() {
        try {
            const response = await fetch(this.streamUrl);
            const data = await response.json();
            if (!data.success) return;

            this.updateKpis(data.kpis);
            this.updateMarkers(data.attendances);
        } catch (error) {
            console.error('Error sincronizando el dashboard en vivo:', error);
        }
    }

    updateKpis(kpis) {
        if (!kpis) return;
        const setVal = (id, val) => {
            const el = document.getElementById(id);
            if (el) el.innerText = val;
        };
        setVal('kpiPresent', kpis.present_count);
        setVal('kpiLate', kpis.late_count);
        setVal('kpiBreak', kpis.on_break_count);
        setVal('kpiAbsent', kpis.absent_count);
    }

    updateMarkers(attendances) {
        if (!attendances) return;

        // Limpiar marcadores previos
        this.employeeMarkers.forEach(m => this.map.removeLayer(m));
        this.employeeMarkers = [];

        attendances.forEach(att => {
            if (att.latitude && att.longitude) {
                const color = att.within_geofence ? '#10b981' : '#ef4444';
                const icon = L.divIcon({
                    className: 'emp-live-marker',
                    html: `<div style="width: 22px; height: 22px; border-radius: 50%; background: ${color}; border: 3px solid #fff; box-shadow: 0 0 10px ${color};"></div>`,
                    iconSize: [22, 22],
                    iconAnchor: [11, 11]
                });

                const marker = L.marker([att.latitude, att.longitude], { icon })
                    .addTo(this.map)
                    .bindPopup(`
                        <div style="color: #0f172a; padding: 4px; min-width: 170px;">
                            ${att.photo_url ? `<img src="${att.photo_url}" style="width: 100%; height: 85px; object-fit: cover; border-radius: 6px; margin-bottom: 5px;">` : ''}
                            <div style="font-weight: 700; font-size: 13px;">${att.user_name}</div>
                            <div style="font-size: 11px; color: #64748b;">${att.branch_name} • ${att.clock_in_at || ''}</div>
                            <div style="margin-top: 4px; font-size: 11px; font-weight: 700; color: ${att.within_geofence ? '#059669' : '#dc2626'};">
                                ${att.within_geofence ? '✓ En Geocerca (' + att.distance_meters + 'm)' : '⚠ Fuera (' + att.distance_meters + 'm)'}
                            </div>
                        </div>
                    `);

                this.employeeMarkers.push(marker);
            }
        });
    }
}

window.TuneSADashboard = TuneSADashboard;
