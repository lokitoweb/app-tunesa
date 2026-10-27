{{--
    Visor de marcación: selfie ampliada, hora exacta, sucursal y mapa con el
    punto GPS contra el radio de ubicación. Uso: openEvidence(attendanceId).
    Los datos llegan de attendance.evidence (JSON) y la selfie con URL firmada.
--}}
<div class="modal fade" id="evidenceModal" tabindex="-1" aria-labelledby="evidenceTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down modal-xl modal-dialog-centered modal-fullscreen-lg-down">
        <div class="modal-content">
            <div class="modal-header">
                <div class="d-flex align-items-center gap-2 min-w-0">
                    <div class="evidence-header-icon">
                        <i class="bi bi-geo-alt-fill"></i>
                    </div>
                    <div class="min-w-0">
                        <h2 class="modal-title fs-6 fw-bold text-truncate mb-0" id="evidenceTitle" style="color: var(--text-primary, #0f172a);">Marcación</h2>
                        <small style="font-size: 0.775rem; color: var(--text-muted, #64748b);" id="evidenceSubtitle"></small>
                    </div>
                </div>
                <div class="ms-auto d-flex align-items-center gap-2">
                    <a id="evidenceFullLink" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1 shadow-sm" href="#" style="border-radius: 6px; font-weight: 600; font-size: 0.775rem; padding: 4px 10px;">
                        <i class="bi bi-box-arrow-up-right"></i>
                        <span>Auditoría completa</span>
                    </a>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar" style="font-size: 0.75rem;"></button>
                </div>
            </div>
            <div class="modal-body">
                <div class="row g-3 align-items-stretch">
                    {{-- Columna izquierda: Tabs + Selfie + Ficha forense con progreso adentro --}}
                    <div class="col-12 col-lg-5 d-flex flex-column" style="height: 504px;">
                        {{-- Selector de momento (Entrada / Salida) estilo segmentado moderno --}}
                        <div class="evidence-moment-tabs mb-2" role="group" aria-label="Momento de la marcación">
                            <button type="button" class="evidence-moment-tab active" data-moment="in">
                                <i class="bi bi-box-arrow-in-right"></i>
                                <span>Entrada</span>
                            </button>
                            <button type="button" class="evidence-moment-tab" data-moment="out">
                                <i class="bi bi-box-arrow-right"></i>
                                <span>Salida</span>
                            </button>
                        </div>

                        {{-- Alerta de integridad si corresponde --}}
                        <div id="evidenceAltered" class="alert alert-danger py-1 px-2 small d-none mb-2" style="border-radius: 6px; font-size: 0.725rem;" role="alert">
                            <i class="bi bi-shield-exclamation me-1"></i><strong>Evidencia alterada:</strong> <span id="evidenceAlteredText"></span>
                        </div>

                        {{-- Contenedor amplio de Selfie (proporción óptima para fotos verticales) --}}
                        <div class="evidence-selfie-box mb-2">
                            <img id="evidencePhoto" alt="Selfie de la marcación" class="w-100 h-100 object-fit-contain d-none" src="data:image/gif;base64,R0lGODlhAQABAAAAACw=">
                            <div id="evidenceNoPhoto" class="d-flex flex-column align-items-center justify-content-center text-secondary h-100 p-2">
                                <div class="evidence-no-photo-icon">
                                    <i class="bi bi-camera-video-off"></i>
                                </div>
                                <span class="fw-medium" style="font-size: 0.8rem;" id="evidenceNoPhotoText">Sin selfie</span>
                            </div>
                        </div>

                        {{-- Ficha de datos forenses con la barra de radio integrada adentro del cuadro --}}
                        <div class="evidence-facts-box flex-grow-1">
                            {{-- Barra de consumo de radio de ubicación DENTRO del cuadro --}}
                            <div class="evidence-radius-usage mb-3">
                                <div class="d-flex justify-content-between small text-secondary mb-1 fw-medium" style="font-size: 0.72rem;">
                                    <span>Distancia vs. radio permitido</span>
                                    <span id="evidenceUsage" class="fw-bold" style="color: var(--text-primary, #0f172a);"></span>
                                </div>
                                <div class="progress" role="progressbar" aria-label="Uso del radio de ubicación" style="height: 6px; border-radius: 3px; background: rgba(148, 163, 184, 0.25);">
                                    <div class="progress-bar bg-success" id="evidenceBar" style="width: 0%; border-radius: 3px;"></div>
                                </div>
                            </div>
                            
                            {{-- Lista con scroll interno fino --}}
                            <div class="evidence-facts-scroll">
                                <dl class="evidence-facts-grid" id="evidenceFacts"></dl>
                            </div>
                        </div>
                    </div>

                    {{-- Columna derecha: Mapa satelital/vectorial con altura exacta y recentrar --}}
                    <div class="col-12 col-lg-7">
                        <div class="position-relative evidence-map-wrapper" style="height: 504px; border-radius: 8px; overflow: hidden; border: 1px solid var(--border-color, #cbd5e1);">
                            <div id="evidenceMap" style="height: 100%; width: 100%;"></div>
                            <button type="button" id="btnCenterEvidenceMap" class="btn btn-sm shadow-sm evidence-map-recenter-btn" title="Recentrar mapa">
                                <i class="bi bi-crosshair"></i>
                                <span>Recentrar</span>
                            </button>
                        </div>
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

        const integrity = p?.photo_integrity;
        el('evidenceAltered').classList.toggle('d-none', integrity !== 'altered' && integrity !== 'missing');
        el('evidenceAlteredText').textContent = integrity === 'missing'
            ? 'falta el archivo de la selfie que se guardó al marcar.'
            : 'la selfie no coincide con la huella que se guardó al recibirla.';

        const radius = d.branch?.radius || 0;
        const facts = p ? [
            ['Hora exacta', p.time],
            ['Sucursal', d.branch?.name || '—'],
            ['Ubicación', p.within_geofence ? 'Dentro del radio permitido' : 'Fuera del radio permitido'],
            ['Distancia', (p.distance_meters ?? '—') + ' m de ' + radius + ' m permitidos'],
            ['Precisión GPS', p.gps_accuracy ? '± ' + p.gps_accuracy + ' m' : '—'],
            ['Ubicación simulada', p.is_mocked ? 'Sí (sospechoso)' : 'No'],
            ['Rostro detectado', p.face_detected === null || p.face_detected === undefined ? '—' : (p.face_detected ? 'Sí' : 'No')],
            ['Hora del celular', p.time_drift_seconds == null ? '—' : (p.time_drift_seconds === 0 ? 'Sincronizada' : 'Desfase de ' + Math.abs(p.time_drift_seconds) + ' s')],
            ['Motivos sospecha', (p.suspicion_labels || []).length ? p.suspicion_labels.join(', ') : 'Ninguno'],
            ['Seguridad móvil', (p.device_flags || []).length ? p.device_flags.join(', ') : 'Sin alertas'],
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
            evidenceMap = L.map('evidenceMap').setView([-12.046374, -77.042793], 12);
            Panel.tiles(evidenceMap);
        }
        if (evidenceLayer) evidenceLayer.remove();
        evidenceLayer = L.featureGroup().addTo(evidenceMap);
        const brand = Panel.cssVar('--brand') || '#3C729E';

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
        modalEl.addEventListener('shown.bs.modal', () => {
            setTimeout(fitMap, 150);
        });
        modalEl.querySelectorAll('[data-moment]').forEach((b) => b.addEventListener('click', () => showMoment(b.dataset.moment)));

        const btnCenter = el('btnCenterEvidenceMap');
        if (btnCenter) {
            btnCenter.addEventListener('click', (e) => {
                e.preventDefault();
                fitMap();
            });
        }
    });
})();
</script>
