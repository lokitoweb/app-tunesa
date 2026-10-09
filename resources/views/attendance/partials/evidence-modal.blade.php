{{--
    Visor de marcación: selfie ampliada, hora exacta, sucursal y mapa con el
    punto GPS contra el radio de la geocerca. Uso: openEvidence(attendanceId).
    Los datos llegan de attendance.evidence (JSON) y la selfie con URL firmada.
--}}
<div id="evidenceModal" class="evidence-modal" role="dialog" aria-modal="true" aria-labelledby="evidenceTitle" onclick="if (event.target === this) closeEvidence()">
    <div class="evidence-box">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.25rem;">
            <div>
                <div class="card-title" id="evidenceTitle">Marcación</div>
                <div class="card-subtitle" id="evidenceSubtitle"></div>
            </div>
            <div style="display: flex; gap: 8px; align-items: center;">
                <a id="evidenceFullLink" class="btn btn-ghost btn-sm" href="#">Auditoría completa</a>
                <button type="button" class="btn btn-ghost btn-sm" onclick="closeEvidence()" aria-label="Cerrar">✕</button>
            </div>
        </div>
        <div class="evidence-grid">
            <div class="evidence-photo">
                <div class="evidence-tabs">
                    <button type="button" class="btn btn-sm btn-primary" data-moment="in" onclick="showEvidenceMoment('in')">Entrada</button>
                    <button type="button" class="btn btn-sm btn-ghost" data-moment="out" onclick="showEvidenceMoment('out')">Salida</button>
                </div>
                <img id="evidencePhoto" alt="Selfie de la marcación" src="data:image/gif;base64,R0lGODlhAQABAAAAACw=">
                <div id="evidenceNoPhoto" class="text-muted" style="display:none; text-align:center; padding: 2rem 0;">Sin selfie</div>
                <dl class="kv" style="margin-top: 1rem;" id="evidenceFacts"></dl>
                <div style="margin-top: 1rem;">
                    <div style="display:flex; justify-content: space-between; font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 6px;">
                        <span>Distancia vs. radio permitido</span><span id="evidenceUsage"></span>
                    </div>
                    <div class="progress" id="evidenceBar"><div style="width: 0%"></div></div>
                </div>
            </div>
            <div id="evidenceMap" class="evidence-map"></div>
        </div>
    </div>
</div>

<script>
(function () {
    let evidenceData = null, evidenceMap = null, evidenceLayer = null;
    const url = id => @json(url('/attendance')) + '/' + id + '/evidence';
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    window.openEvidence = async function (id) {
        const modal = document.getElementById('evidenceModal');
        modal.classList.add('open');
        document.getElementById('evidenceTitle').textContent = 'Cargando…';
        try {
            const res = await fetch(url(id), { headers: { 'Accept': 'application/json' } });
            if (!res.ok) throw new Error(res.status);
            evidenceData = await res.json();
        } catch (e) {
            document.getElementById('evidenceTitle').textContent = 'No se pudo cargar la marcación';
            return;
        }
        const d = evidenceData;
        document.getElementById('evidenceTitle').textContent = (d.employee?.name || 'Colaborador') + ' · ' + (d.work_date || '');
        document.getElementById('evidenceSubtitle').textContent = (d.branch?.name || 'Sin sucursal') + (d.shift ? ' · ' + d.shift : '');
        document.getElementById('evidenceFullLink').href = @json(url('/attendance')) + '/' + d.id;
        showEvidenceMoment(d.clock_in ? 'in' : 'out');
    };

    window.closeEvidence = function () {
        document.getElementById('evidenceModal').classList.remove('open');
    };

    window.showEvidenceMoment = function (moment) {
        const d = evidenceData; if (!d) return;
        const p = moment === 'in' ? d.clock_in : d.clock_out;
        document.querySelectorAll('.evidence-tabs button').forEach(b => {
            b.classList.toggle('btn-primary', b.dataset.moment === moment);
            b.classList.toggle('btn-ghost', b.dataset.moment !== moment);
        });

        const img = document.getElementById('evidencePhoto');
        const none = document.getElementById('evidenceNoPhoto');
        if (p?.photo_url) { img.src = p.photo_url; img.style.display = ''; none.style.display = 'none'; }
        else { img.style.display = 'none'; none.style.display = ''; none.textContent = p ? 'Sin selfie' : 'Aún no marca ' + (moment === 'in' ? 'entrada' : 'salida'); }

        const radius = d.branch?.radius || 0;
        const facts = p ? [
            ['Hora exacta', p.time],
            ['Sucursal', d.branch?.name || '—'],
            ['Geocerca', p.within_geofence ? '✓ Dentro del radio' : '⚠ Fuera del radio'],
            ['Distancia', (p.distance_meters ?? '—') + ' m de ' + radius + ' m permitidos'],
            ['Precisión GPS', p.gps_accuracy ? '± ' + p.gps_accuracy + ' m' : '—'],
            ['Ubicación simulada', p.is_mocked ? 'Sí (sospechoso)' : 'No'],
            ['Coordenadas', p.latitude != null ? p.latitude.toFixed(6) + ', ' + p.longitude.toFixed(6) : '—'],
            ['Dispositivo', p.device || '—'],
        ] : [];
        document.getElementById('evidenceFacts').innerHTML = facts.map(([k, v]) => `<dt>${esc(k)}</dt><dd>${esc(v)}</dd>`).join('');

        const usage = p?.radius_usage ?? 0;
        const bar = document.getElementById('evidenceBar');
        bar.className = 'progress ' + (p && !p.within_geofence ? 'is-danger' : 'is-success');
        bar.firstElementChild.style.width = Math.min(100, usage) + '%';
        document.getElementById('evidenceUsage').textContent = p && p.radius_usage != null
            ? usage + '% del radio' + (p.excess_meters > 0 ? ' · ' + p.excess_meters + ' m de exceso' : '')
            : '';

        drawEvidenceMap(d, p);
    };

    function drawEvidenceMap(d, p) {
        if (!window.L) return;
        if (!evidenceMap) {
            evidenceMap = L.map('evidenceMap');
            L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { maxZoom: 19, attribution: '© OpenStreetMap · © CARTO' }).addTo(evidenceMap);
        }
        if (evidenceLayer) evidenceLayer.remove();
        evidenceLayer = L.featureGroup().addTo(evidenceMap);

        if (d.branch) {
            L.circle([d.branch.latitude, d.branch.longitude], { radius: d.branch.radius, color: '#6366f1', fillColor: '#6366f1', fillOpacity: 0.18, weight: 2 })
                .bindTooltip(esc(d.branch.name) + ' · radio ' + d.branch.radius + ' m').addTo(evidenceLayer);
            L.circleMarker([d.branch.latitude, d.branch.longitude], { radius: 5, color: '#c7d2fe', fillOpacity: 1 }).addTo(evidenceLayer);
        }
        if (p && p.latitude != null) {
            const color = p.within_geofence ? '#10b981' : '#ef4444';
            if (p.gps_accuracy) {
                L.circle([p.latitude, p.longitude], { radius: p.gps_accuracy, color, weight: 1, dashArray: '4 6', fillOpacity: 0.06 }).addTo(evidenceLayer);
            }
            L.circleMarker([p.latitude, p.longitude], { radius: 9, color: '#fff', weight: 3, fillColor: color, fillOpacity: 1 })
                .bindPopup('<b>Marcación ' + esc(p.time) + '</b><br>' + (p.distance_meters ?? '?') + ' m del centro').addTo(evidenceLayer).openPopup();
            if (d.branch) {
                L.polyline([[d.branch.latitude, d.branch.longitude], [p.latitude, p.longitude]], { color, weight: 2, dashArray: '5 8' }).addTo(evidenceLayer);
            }
        }
        setTimeout(() => {
            evidenceMap.invalidateSize();
            const b = evidenceLayer.getBounds();
            if (b.isValid()) evidenceMap.fitBounds(b.pad(0.4), { maxZoom: 18 });
        }, 50);
    }

    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeEvidence(); });
})();
</script>
