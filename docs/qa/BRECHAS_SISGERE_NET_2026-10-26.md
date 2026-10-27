# Brechas sisgere (.NET) → sistema web · 26/10/2026

Fuentes (solo lectura): código VB.NET en `PROGRAMACION INTEGRACION\sisgere` (arranca en `FrmMenu`, login
`FrmLogin`), ejecutable `bin\debug\sisgere_prog.exe` y respaldo `bettson\bettson.bak` (SQL Server,
base Bdrequerimientos; ~150 procedimientos y ~20 vistas leídos del respaldo sin restaurarlo).
REA (`@morluto/rea`) no se usó: requiere macOS y Hopper Disassembler; con el código fuente no hace falta.

## Menú del .NET y estado en el web
| Menú .NET | Formulario | Web | Estado |
|---|---|---|---|
| Maestros › Registro Proveedor | FrmRegProveedor | `/suppliers` | Parcial: falta catálogo de bancos y el campo "T costo" |
| Requerimientos › Requerimientos | FrmReq (19 pestañas) | `/requisitions` | Parcial (ver abajo) |
| Requerimientos › Compras x Producto | FrmConsultar | `/purchases/by-product` | Hecho (sin exportar) |
| Programación Pagos › Programación | FrmReqProg (17 pestañas) | `/treasury`, `/fixed-costs` | Parcial |
| Programación Pagos › Caja Chica | Fcaja_chica | `/petty-cash` | Hecho |
| Tesorería › Pagos | FrmRegpagos | `/treasury` | Parcial |
| Tesorería › Bancos | FRMBANCOS | cuentas de la empresa | Parcial: falta egreso bancario directo |
| RR. HH. › Programación | FrmRegrrhh (13 pestañas) | `/payroll-payments`, `/employees` | Parcial |
| RR. HH. › Importar Planilla | FrmIplanilla | — | Falta |
| Logística (6), Finanzas (4), Administrativos | "MODULO EN CONSTRUCCION" en el .NET | Kardex y combustible ya existen en el web | No es brecha |

Perfiles del .NET (Administrador, Contabilidad, Logística, Supervisor, Rrhh, Finanzas) ocultaban menús
enteros; el web los cubre con roles y permisos por pantalla y acción.

## Lo que falta, priorizado
| # | Brecha | Formulario / procedimiento .NET | Tamaño |
|---|---|---|---|
| 1 | Precio unitario, descuento e IGV por ítem; OC/OS impresa con valor venta e IGV | FrmReq OC/OS, `Modificar_detalle_id`, botones Igv / S/Igv | Grande |
| 2 | Migración del histórico del .bak (requerimientos, cuotas, pagos, proveedores) | todo | Grande |
| 3 | Solicitud de cotización por rubro, PDF y correo; etapa DIFUSION | `CREAR_SOLCOTIZACION`, `CRsolcoti` | Medio |
| 4 | Cuotas editables a mano y anticipos que descuentan la deuda | `Tcuotadoc_crear`, `mostrar_anticipo` | Medio |
| 5 | Reportes PDF/Excel: programados, sin programar, general, cancelaciones por banco y por requerimiento, costos fijos (resumen, consolidado, avance), centro de costos por placa, estadística de caja chica, liquidación, colaboradores | `CR*` | Grande (cada uno pequeño) |
| 6 | Exportar a Excel las grillas de Tesorería, Programación y Compras | FrmReqProg Button32 | Pequeño |
| 7 | Importar colaboradores desde Excel con altas y bajas | FrmIplanilla | Medio |
| 8 | Importar ítems de un requerimiento desde Excel | FrmEXCEL | Pequeño |
| 9 | Correlativo de ODP y recibo de caja | `requerimiento_mostrar_nroODP`, `crear_recibo` | Pequeño |
| 10 | Catálogos de bancos y detracción (motivo y %); "T costo" del proveedor | `TBANCO_LISTAR`, `Tdetraccion_LISTAR` | Pequeño |
| 11 | Flujo de efectivo por código/concepto en la cuota | `FLUJOEFECT_requerimiento01`, `mostrar_flujo` | Medio |
| 12 | Bloquear generar dos veces costos fijos del mismo año, mes, rubro y empresa | `validar_cabecera` | Pequeño |
| 13 | Corregir un pago, reemplazar comprobante, corregir monto de una orden, eliminar OP | `corregir_PAGO`, `corregir_requerimiento02` | Medio |
| 14 | Egreso bancario directo (pagado en el acto) | FRMBANCOS | Medio |
| 15 | Alta y corrección de productos desde el requerimiento | `Tproducto_crear` | Pequeño |
| 16 | Aviso de cuotas por vencer | FrmNOTIFICADOR, `mostrar_alerta01` | Pequeño |
| 17 | Autorizar varias cuotas marcadas a la vez | FrmRegpagos | Pequeño |
| 18 | Consulta RENIEC por DNI | FrmReq / FrmReqProg | Pequeño (requiere proveedor del servicio) |

## Estado al 27/10/2026 (verificado en tests y en el navegador)
| # | Estado | Dónde |
|---|---|---|
| 1 | Hecho | Emitir OC/OS: precio, descuento, Igv / S/Igv; PDF con valor venta, IGV y total |
| 2 | Descartada (27/10/2026) | La empresa no necesita la data histórica; se replica solo la lógica, estructura y flujos del .NET |
| 3 | Hecho | Requerimiento › Solicitud de cotización: SC por proveedor, entrega, condición, PDF por correo. La etapa DIFUSION no se agregó: el web pasa de Aprobado a Cotización |
| 4 | Hecho | Cuotas a mano al emitir; anticipos (pago directo marcado) que se descuentan de la siguiente orden del proveedor |
| 5 | Hecho | Finanzas › Reportes de finanzas (9 en Excel) y Colaboradores › Exportar. Liquidación: el .NET no la calculaba |
| 6 | Hecho | Exportar a Excel en Tesorería, Costos fijos y Compras por producto |
| 7 | Hecho | Colaboradores › Importar planilla: revisión, alta de DNI nuevos, cuenta pendiente de verificar (el .NET tampoco daba de baja) |
| 8 | Hecho | Nuevo requerimiento › Importar Excel |
| 9 | Hecho | Pago directo tipo Orden de pago sin número: correlativo OP-…; el recibo correlativo ya estaba en Caja chica |
| 10 | Hecho | Detracción por motivo con su % (catálogo del respaldo), en soles enteros; neto a pagar al proveedor. Bancos: ya había catálogo |
| 11 | Hecho | Concepto del flujo de efectivo (EFE, método directo) en orden, pago directo y planilla; Flujo de caja › Por flujo de efectivo |
| 12 | No es brecha | En el .NET la validación está comentada; generar requerimientos desde costos fijos = pagar la cuota en el web |
| 13 | Hecho | Tesorería: corregir pago directo sin pagos; corregir cuenta, operación y voucher de un pago |
| 14 | Hecho (con control) | Tesorería › Pago directo (PG-…). Se paga por la cola normal: quien autoriza no paga |
| 15 | Hecho | Finanzas › Productos: corregir y eliminar solo los no usados |
| 16 | Hecho | El .NET avisaba "Se registró un pago": aviso en la campana a quien pidió |
| 17 | Hecho | Tesorería › Programadas: marcar y autorizar varias |
| 18 | Parcial | RUC: hecho con Migo (Proveedores, token en Integraciones). DNI: la configuración de Migo existe, falta usarla en Colaboradores y en el pago a personas |

## Reglas que el web hace distinto (a propósito o a decidir)
- Aprobador: el .NET tenía fijo "MERCY NEGREIROS PIZAN" en el código; el web usa el permiso de aprobar.
- Separación de funciones: en el web quien autoriza no paga y el pago exige OC/OS, factura y cuenta verificada; el .NET solo exigía la autorización.
- Amortizaciones: el .NET limitaba a 4 depósitos por cuota; el web no limita (tope = saldo).
- Cuotas: el .NET las dejaba editar y con anticipo; el web genera cuotas iguales (ver brecha 4).
- Liquidación: el .NET **no la calculaba**, se escribía a mano; el web importa el monto: equivalente.
- Login: el .NET era empresa + clave sin usuario; el web, usuario con roles.
- Seguridad: el .NET tiene la contraseña del correo Gmail escrita en el código (FrmReq.vb:2575 y 3774) y Crystal conecta como "sa". **Conviene cambiar esas contraseñas si siguen vigentes.**
