{{--
    Visor de marcación: selfie ampliada, hora exacta, sucursal y mapa con el
    punto GPS contra el radio de la geocerca. Uso: openEvidence(attendanceId).
    Los datos llegan de attendance.evidence (JSON) y la selfie con URL firmada.
--}}
<div class="modal fade" id="evidenceModal" tabindex="-1" aria-labelledby="evidenceTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-lg-down">
        <div class="modal-content">
            <div class="modal-header">
                <div class="min-w-0">
                    <h2 class="modal-title fs-6 fw-semibold text-truncate" id="evidenceTitle">Marcación</h2>
                    <small class="text-body-secondary" id="evidenceSubtitle"></small>
                </div>
                <div class="ms-auto d-flex align-items-center gap-2">
                    <a id="evidenceFullLink" class="btn btn-sm btn-outline-primary" href="#"><i class="bi bi-box-arrow-up-right me-1"></i>Auditoría completa</a>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
            </div>
            <div class="modal-body">
                <div class="row g-4">
                    <div class="col-12 col-lg-5">
                        <div class="btn-group w-100 mb-3" role="group" aria-label="Momento de la marcación">
                            <button type="button" class="btn btn-outline-primary active" data-moment="in">Entrada</button>
                            <button type="button" class="btn btn-outline-primary" data-moment="out">Salida</button>
                        </div>
                        <div class="ratio ratio-4x3 bg-body-tertiary rounded overflow-hidden mb-3">
                            <img id="evidencePhoto" alt="Selfie de la marcación" class="w-100 h-100 object-fit-contain d-none" src="data:image/gif;base64,R0lGODlhAQABAAAAACw=">
                            <div id="evidenceNoPhoto" class="d-flex align-items-center justify-content-center text-body-secondary">
                                <span><i class="bi bi-camera-video-off me-1"></i><span id="evidenceNoPhotoText">Sin selfie</span></span>
                            </div>
                        </div>
                        <dl class="kv small" id="evidenceFacts"></dl>
                        <div class="mt-3">
                            <div class="d-flex justify-content-between small text-body-secondary mb-1">
                                <span>Distancia vs. radio permitido</span><span id="evidenceUsage"></span>
                            </div>
                            <div class="progress" role="progressbar" aria-label="Uso del radio de la geocerca" style="height: 8px;">
                                <div class="progress-bar bg-success" id="evidenceBar" style="width: 0%"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-7">
                        <div id="evidenceMap" class="map-frame rounded" style="height: 100%; min-height: 380px;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    let evidenceData = null, evidenceMap = null, evidenceLayer = null, currentMoment = 'in';
    const base = @json(url('/attendance'));
    const el = (id) => document.getElementById(id);

    window.openEvidence = async function (id) {
        const modalEl = el('evidenceModal');
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
        evidenceData = null;
        el('evidenceTitle').textContent = 'Cargando…';
        el('evidenceSubtitle').textContent = '';
        try {
            const res = await fetch(base + '/' + id + '/evidence', { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error(res.status);
            evidenceData = await res.json();
        } catch (e) {
            el('evidenceTitle').textContent = 'No se pudo cargar la marcación';
            return;
        }
        const d = evidenceData;
        el('evidenceTitle').textContent = (d.employee?.name || 'Colaborador') + ' · ' + (d.work_date_label || d.work_date || '');
        el('evidenceSubtitle').textContent = (d.branch?.name || 'Sin sucursal') + (d.shift ? ' · ' + d.shift : '');
        el('evidenceFullLink').href = base + '/' + d.id;
        showMoment(d.clock_in ? 'in' : 'out');
    };

    function showMoment(moment) {
        currentMoment = moment;
        const d = evidenceData; if (!d) return;
        const p = moment === 'in' ? d.clock_in : d.clock_out;
        document.querySelectorAll('#evidenceModal [data-moment]').forEach((b) => b.classList.toggle('active', b.dataset.moment === moment));

        const img = el('evidencePhoto');
        if (p?.photo_url) {
            img.src = p.photo_url;
            img.classList.remove('d-none');
            el('evidenceNoPhoto').classList.add('d-none');
        } else {
            img.classList.add('d-none');
            el('evidenceNoPhoto').classList.remove('d-none');
            el('evidenceNoPhotoText').textContent = p ? 'Sin selfie' : 'Aún no marca ' + (moment === 'in' ? 'entrada' : 'salida');
        }

        const radius = d.branch?.radius || 0;
        const facts = p ? [
            ['Hora exacta', p.time],
            ['Sucursal', d.branch?.name || '—'],
            ['Geocerca', p.within_geofence ? 'Dentro del radio' : 'Fuera del radio'],
            ['Distancia', (p.distance_meters ?? '—') + ' m de ' + radius + ' m permitidos'],
            ['Precisión GPS', p.gps_accuracy ? '± ' + p.gps_accuracy + ' m' : '—'],
            ['Ubicación simulada', p.is_mocked ? 'Sí (sospechoso)' : 'No'],
            ['Rostro detectado', p.face_detected === null || p.face_detected === undefined ? '—' : (p.face_detected ? 'Sí' : 'No')],
            ['Hora del celular', p.time_drift_seconds == null ? '—' : (p.time_drift_seconds === 0 ? 'Sincronizada' : 'Desfase de ' + Math.abs(p.time_drift_seconds) + ' s')],
            ['Motivos de sospecha', (p.suspicion_labels || []).length ? p.suspicion_labels.join(', ') : 'Ninguno'],
            ['Coordenadas', p.latitude != null ? Number(p.latitude).toFixed(6) + ', ' + Number(p.longitude).toFixed(6) : '—'],
            ['Dispositivo', p.device || '—'],
        ] : [];
        el('evidenceFacts').innerHTML = facts.map(([k, v]) => `<dt>${Panel.esc(k)}</dt><dd>${Panel.esc(v)}</dd>`).join('');

        const usage = p?.radius_usage ?? 0;
        const bar = el('evidenceBar');
        bar.className = 'progress-bar ' + (p && !p.within_geofence ? 'bg-danger' : 'bg-success');
        bar.style.width = Math.min(100, usage) + '%';
        el('evidenceUsage').textContent = p && p.radius_usage != null
            ? usage + '% del radio' + (p.excess_meters > 0 ? ' · ' + p.excess_meters + ' m de exceso' : '')
            : '';

        drawMap(d, p);
    }

    function drawMap(d, p) {
        if (!window.L) return;
        if (!evidenceMap) {
            // Vista inicial antes de agregar capas: los círculos la necesitan para calcular límites
            evidenceMap = L.map('evidenceMap').setView([-12.046374, -77.042793], 12);
            Panel.tiles(evidenceMap);
        }
        if (evidenceLayer) evidenceLayer.remove();
        evidenceLayer = L.featureGroup().addTo(evidenceMap);
        const brand = Panel.cssVar('--brand') || '#1E40AF';

        if (d.branch) {
            L.circle([d.branch.latitude, d.branch.longitude], { radius: d.branch.radius, color: brand, fillColor: brand, fillOpacity: .15, weight: 2 })
                .bindTooltip(Panel.esc(d.branch.name) + ' · radio ' + d.branch.radius + ' m').addTo(evidenceLayer);
            L.circleMarker([d.branch.latitude, d.branch.longitude], { radius: 5, color: brand, fillOpacity: 1 }).addTo(evidenceLayer);
        }
        if (p && p.latitude != null) {
            const color = p.within_geofence ? '#198754' : '#dc3545';
            if (p.gps_accuracy) {
                L.circle([p.latitude, p.longitude], { radius: p.gps_accuracy, color, weight: 1, dashArray: '4 6', fillOpacity: .06 }).addTo(evidenceLayer);
            }
            L.circleMarker([p.latitude, p.longitude], { radius: 9, color: '#fff', weight: 3, fillColor: color, fillOpacity: 1 })
                .bindPopup('<strong>Marcación ' + Panel.esc(p.time) + '</strong><br>' + Panel.esc(p.distance_meters ?? '?') + ' m del centro')
                .addTo(evidenceLayer).openPopup();
            if (d.branch) {
                L.polyline([[d.branch.latitude, d.branch.longitude], [p.latitude, p.longitude]], { color, weight: 2, dashArray: '5 8' }).addTo(evidenceLayer);
            }
        }
        fitMap();
    }

    function fitMap() {
        if (!evidenceMap || !evidenceLayer) return;
        evidenceMap.invalidateSize();
        const b = evidenceLayer.getBounds();
        if (b.isValid()) evidenceMap.fitBounds(b.pad(.4), { maxZoom: 18 });
        else evidenceMap.setView([-12.046374, -77.042793], 12);
    }

    document.addEventListener('DOMContentLoaded', () => {
        const modalEl = el('evidenceModal');
        // El mapa necesita el tamaño final del modal para pintarse completo
        modalEl.addEventListener('shown.bs.modal', fitMap);
        modalEl.querySelectorAll('[data-moment]').forEach((b) => b.addEventListener('click', () => showMoment(b.dataset.moment)));
    });
})();
</script>
