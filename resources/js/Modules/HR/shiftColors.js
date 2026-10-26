// Colores de los horarios: los predefinidos y la regla de bloqueo por agencia.
// La misma regla la valida el servidor (ShiftWebController::colorTakenIn).

export const PRESET_COLORS = [
    '#0284C7', // Azul corporativo
    '#2563EB', // Azul real
    '#059669', // Esmeralda
    '#D97706', // Ámbar
    '#7C3AED', // Púrpura
    '#DC2626', // Rojo
    '#0D9488', // Verde azulado
    '#475569', // Pizarra
];

/**
 * Nombre del horario que ya usa ese color en la agencia, o undefined si está libre.
 * Cuentan los de la misma agencia y los que no tienen agencia (la programación los
 * ofrece en todas). Sin agencia elegida no se bloquea nada.
 *
 * @param {Array<{id:number,name:string,color:string,branch_id:?number}>} shifts
 */
export function colorOwner(shifts, branchId, color, exceptId = null) {
    if (!branchId || !color) return undefined;
    const c = String(color).toUpperCase();
    return shifts.find((s) => s.id !== exceptId && s.color && s.color.toUpperCase() === c
        && (s.branch_id == null || Number(s.branch_id) === Number(branchId)))?.name;
}

/** Primer color predefinido libre en la agencia (o el primero si están todos usados) */
export function firstFreeColor(shifts, branchId, exceptId = null) {
    return PRESET_COLORS.find((c) => !colorOwner(shifts, branchId, c, exceptId)) || PRESET_COLORS[0];
}
