{{--
    Ubigeo de la sede (departamento > provincia > distrito, public/data/ubigeo-pe.json) y ubicación
    automática de la dirección en el mapa. Lo usan el modal de Sucursales y la página de la sede:
    const ubigeo = BranchUbigeo({ city, province, district, address, status, onLocate(lat, lng, zoom, label) });
    ubigeo.set(city, province, district) llena los selects (una sede antigua con la ciudad en "Región",
    p. ej. "Trujillo", se reconoce como provincia de su departamento).
--}}
<script>
window.BranchUbigeo = function ({ city, province, district, address, status, onLocate }) {
    const el = (id) => document.getElementById(id);
    const [cityEl, provinceEl, districtEl, addressEl, statusEl] = [el(city), el(province), el(district), el(address), el(status)];
    const hint = statusEl.textContent;
    let data = {};
    const ready = fetch('{{ asset('data/ubigeo-pe.json') }}').then((r) => r.json()).then((j) => { data = j; }).catch(() => {});
    const same = (a, b) => a.localeCompare(b, 'es', { sensitivity: 'base' }) === 0;

    {{-- Un valor guardado que no está en la lista se conserva como "(actual)" --}}
    function fill(select, items, placeholder, value) {
        select.innerHTML = '';
        select.add(new Option(placeholder, ''));
        const sorted = [...items].sort((a, b) => a.localeCompare(b, 'es'));
        if (value && !sorted.includes(value)) select.add(new Option(`${value} (actual)`, value));
        sorted.forEach((name) => select.add(new Option(name, name)));
        select.value = value || '';
        select.disabled = select.options.length <= 1;
    }
    const provinces = () => data[cityEl.value] || {};
    const fillProvinces = (value) => fill(provinceEl, Object.keys(provinces()), cityEl.value ? 'Elige la provincia' : 'Elige el departamento', value);
    const fillDistricts = (value) => fill(districtEl, provinces()[provinceEl.value] || [], provinceEl.value ? 'Elige el distrito' : 'Elige la provincia', value);

    function setStatus(text, tone) {
        statusEl.textContent = text;
        statusEl.className = 'form-text small' + (tone ? ` text-${tone}` : '');
    }

    {{-- Geocodificador del sistema (Nominatim o Google, según la empresa). Prueba la dirección completa, luego la
         calle sin número y al final el distrito; de cada búsqueda toma el primer resultado que sea de la calle
         escrita (así "Túpac Yupanqui 123" no cae en el 123 de otra calle) --}}
    const fold = (t) => String(t).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    const STREET_TYPE = /^(av|avenida|jr|jiron|calle|ca|cl|psje|pasaje|prol|prolongacion|carretera|mz|lt|urb|n|nro|no)$/;
    let timer = null, seq = 0;
    function locate() {
        clearTimeout(timer);
        timer = setTimeout(async () => {
            const street = addressEl.value.trim();
            const area = [districtEl.value, provinceEl.value, cityEl.value].filter(Boolean);
            if (street.length < 4 && !area.length) return;
            const noNumber = street.replace(/\s+(n[°º.]?\s*)?\d+[\w-]*.*$/i, '').trim();
            const words = fold(noNumber).split(/[^a-z0-9]+/).filter((w) => w.length >= 3 && !STREET_TYPE.test(w));
            const isStreet = (label) => words.length > 0 && words.every((w) => fold(label).includes(w));
            const join = (parts) => [...parts, 'Perú'].filter(Boolean).join(', ');
            const tries = [];
            if (street.length >= 4) tries.push({ q: join([street, ...area]), zoom: 19, kind: 'exact' });
            if (noNumber.length >= 4 && noNumber !== street) tries.push({ q: join([noNumber, ...area]), zoom: 17, kind: 'street' });
            if (area.length) tries.push({ q: join(area), zoom: 15, kind: 'area' });
            const mine = ++seq;
            setStatus('Ubicando la dirección en el mapa…');
            for (const t of tries) {
                try {
                    const res = await fetch(`{{ url('/geocode/search') }}?limit=5&q=${encodeURIComponent(t.q)}`, { headers: { Accept: 'application/json' } });
                    const json = await res.json();
                    if (mine !== seq) return;
                    const results = (json && json.success && json.data) || [];
                    const hit = t.kind === 'area' ? results[0] : results.find((r) => isStreet(r.label));
                    if (!hit) continue;
                    {{-- La etiqueta del pin es la dirección física escrita, para que el mapa y el campo digan lo mismo --}}
                    onLocate(Number(hit.lat), Number(hit.lng), t.zoom, street || hit.label);
                    if (t.kind === 'exact') setStatus(`Ubicada: ${hit.label}`, 'success');
                    else if (t.kind === 'street') setStatus(`Encontramos la calle, no el número exacto (${hit.label}). Arrastra el pin a la puerta.`, 'warning');
                    else setStatus(`No encontramos la calle; el pin quedó en ${hit.label}. Arrastra el pin al punto exacto.`, 'warning');
                    return;
                } catch (e) {
                    if (mine !== seq) return;
                }
            }
            if (mine === seq) setStatus('No se encontró la dirección. Revisa el distrito o ubica el pin en el mapa.', 'danger');
        }, 900);
    }

    cityEl.addEventListener('change', () => { fillProvinces(''); fillDistricts(''); locate(); });
    provinceEl.addEventListener('change', () => { fillDistricts(''); locate(); });
    districtEl.addEventListener('change', locate);
    addressEl.addEventListener('input', locate);

    return {
        {{-- La dirección física manda: mover el pin a mano solo afina el punto (la puerta) y no la cambia.
             Si el campo está vacío, se completa con la dirección del punto (reverse). --}}
        async pinMoved(marker, lat, lng) {
            seq++;
            clearTimeout(timer);
            if (!addressEl.value.trim()) {
                BranchUbigeo.pin(marker, 'Buscando la dirección…');
                const found = await BranchUbigeo.reverse(lat, lng);
                if (found && !addressEl.value.trim()) addressEl.value = found.slice(0, 255);
            }
            BranchUbigeo.pin(marker, addressEl.value.trim() || 'Punto elegido en el mapa');
            setStatus('Pin ajustado a mano. La dirección física no cambia.', 'success');
        },
        async set(cityValue, provinceValue, districtValue) {
            await ready;
            if (cityValue && !data[cityValue]) {
                const dept = Object.keys(data).find((d) => Object.keys(data[d]).some((p) => same(p, cityValue)));
                if (dept) {
                    provinceValue = provinceValue || Object.keys(data[dept]).find((p) => same(p, cityValue));
                    cityValue = dept;
                    cityEl.querySelectorAll('option[data-legacy]').forEach((o) => o.remove());
                }
            }
            if (cityValue && ![...cityEl.options].some((o) => o.value === cityValue)) {
                const legacy = new Option(`${cityValue} (actual)`, cityValue);
                legacy.dataset.legacy = '1';
                cityEl.add(legacy, 1);
            }
            cityEl.value = cityValue || '';
            fillProvinces(provinceValue);
            fillDistricts(districtValue);
            seq++;
            setStatus(hint);
        },
    };
};

{{-- Dirección del punto, encima del pin (las primeras partes, como en un buscador de mapas) --}}
BranchUbigeo.pin = function (marker, text) {
    marker.unbindTooltip();
    if (!text) return;
    let parts = String(text).split(',').map((p) => p.trim()).filter((p) => p && !/^\d{5}$/.test(p) && !/^per[uú]$/i.test(p));
    {{-- "123, Delfín Corcuera, ..." → "Delfín Corcuera 123, ..." --}}
    if (parts.length > 1 && /^\d+[\w-]*$/.test(parts[0])) parts = [`${parts[1]} ${parts[0]}`, ...parts.slice(2)];
    const short = parts.slice(0, 2).join(', ');
    marker.bindTooltip(short, { permanent: true, direction: 'top', offset: [0, -38], className: 'map-pin-label' }).openTooltip();
};

{{-- Dirección de un punto del mapa (geocodificación inversa del sistema) --}}
BranchUbigeo.reverse = async function (lat, lng) {
    try {
        const res = await fetch(`{{ url('/geocode/reverse') }}?lat=${lat}&lng=${lng}`, { headers: { Accept: 'application/json' } });
        const json = await res.json();
        const d = json && json.data;
        return typeof d === 'string' ? d : (d && d.address) || null;
    } catch (e) {
        return null;
    }
};
</script>
