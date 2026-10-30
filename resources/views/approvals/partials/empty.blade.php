<tr class="row-empty">
    <td colspan="{{ $cols }}">
        <div class="dash-empty">
            <span class="dash-empty-icon"><i class="bi bi-check2-all"></i></span>
            <p class="dash-empty-title">No hay {{ $what }} {{ strtolower($statusLabels[$status] ?? '') }}</p>
            <p class="dash-empty-text">No se encontraron {{ $what }} con este estado.</p>
        </div>
    </td>
</tr>
