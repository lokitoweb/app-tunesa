# Brechas sisgere (.NET) → sistema web · 26/10/2026

Fuente revisada: `C:\Users\jloyo\OneDrive\Desktop\bettson`. La carpeta solo tiene **`bettson.bak`** (290 MB,
respaldo de SQL Server 2008 R2 de la base **Bdrequerimientos**). **No hay código fuente VB.NET ni el .exe**,
así que la comparación es por **procedimientos almacenados (~150) y vistas (~20)** leídos del respaldo en
modo solo lectura (no se restauró nada). Para mapear formulario por formulario hace falta el código fuente.

## Ya cubierto en el web (verificado)
Requerimientos (crear, editar, aprobar/rechazar, anular, correlativo con bloqueo), OC/OS, factura del
proveedor, compras por producto, proveedores con cuentas y consulta de RUC, adjuntos, cuotas, programar,
autorizar y registrar pagos con amortización, cuentas de origen, vouchers, orden de pago en PDF, caja chica,
costos fijos por lote, conceptos de planilla, colaboradores, placa y km. El web agrega lo que el .NET no
tenía: kardex con costo promedio, combustible por vehículo, multiempresa con roles y permisos.

## Falta o está incompleto (priorizado)
| # | Brecha | .NET (procedimiento / tabla) | Estado web |
|---|---|---|---|
| 1 | **Migración del histórico** (requerimientos, cuotas, pagos, proveedores) | todo el .bak | `LegacyLinker` y `legacy_id_map` existen pero nada los usa |
| 2 | Precio por ítem (unitario, descuento, subtotal) | `tbldetalle`, `TDETALLE_crear` | `requisition_items` solo cantidad y justificación |
| 3 | OC impresa con precio unitario, valor venta e IGV | `VORDEN_COMPRA` (sub_total/1.18) | solo producto, unidad, cantidad y total |
| 4 | Solicitud de cotización a proveedores | `sol_cotizacion`, `CREAR_SOLCOTIZACION` | no existe |
| 5 | Cálculo de liquidación (CTS, gratificación, vacaciones truncas) | `Modificar_COLABORADOR_liquidacion`, `vliquidacion` | solo monto importado del Excel |
| 6 | Anticipos de pago | `tblcuota_doc.anticipo`, `VANTICIPOS`, `vadelantos_cf` | no existe |
| 7 | Flujo de efectivo por código/concepto | `flujo_efectivo`, `FLUJOEFECT_requerimiento` | agrupa por clasificación |
| 8 | Periodos de planilla por empresa | `periodo_empresa`, `validar_periodo` | solo el índice único del pago |
| 9 | Correlativo de ODP y recibo de requerimiento | `requerimiento_mostrar_nroODP`, `crear_recibo`, `Vrecibo` | no existen |
| 10 | Catálogos: bancos, tipos de detracción, documentos del proveedor | `tblbanco`, `detraccion`, `tbldocumento` | texto libre / no existen |
| 11 | Alertas de cuotas por vencer | `mostrar_alerta*` | solo contador de pendientes por autorizar |
| 12 | Exportar reportes de Tesorería y Compras a Excel | `Vrep_resumen`, `Vmostrarpagosprog`… | solo pantallas |
| 13 | Corregir un pago ya registrado | `corregir_PAGO` | no existe |
| 14 | Mantenimiento de productos | `Tproducto_crear`, `Modificar_producto` | tabla sí, pantalla no |

Desconocidas (solo nombradas en el respaldo, sin uso claro): `configuracion`, `sapwin`, columnas `rd`, `rt`.
Detalle completo con evidencia en ambos lados: informe del agente de la sesión del 26/10.
