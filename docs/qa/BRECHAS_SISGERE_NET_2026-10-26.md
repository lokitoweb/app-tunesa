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
| 18 | Hecho | Proveedores: consulta de RUC (SUNAT) y de DNI (RENIEC) con Migo, como FrmReq/FrmReqProg (los únicos formularios del .NET que la usaban). Se activa con el token de Migo en Integraciones |

## Reglas que el web hace distinto (a propósito o a decidir)
- Aprobador: el .NET tenía fijo "MERCY NEGREIROS PIZAN" en el código; el web usa el permiso de aprobar.
- Separación de funciones: en el web quien autoriza no paga y el pago exige OC/OS, factura y cuenta verificada; el .NET solo exigía la autorización.
- Amortizaciones: el .NET limitaba a 4 depósitos por cuota; el web no limita (tope = saldo).
- Cuotas: el .NET las dejaba editar y con anticipo; el web genera cuotas iguales (ver brecha 4).
- Liquidación: el .NET **no la calculaba**, se escribía a mano; el web importa el monto: equivalente.
- Login: el .NET era empresa + clave sin usuario; el web, usuario con roles.
- Seguridad: el .NET tiene la contraseña del correo Gmail escrita en el código (FrmReq.vb:2575 y 3774), el token de Migo también (FrmReq.vb:1559, FrmReqProg.vb) y Crystal conecta como "sa". **Conviene cambiar esas contraseñas y pedir a Migo un token nuevo si siguen vigentes.**

## Segunda auditoría (27/10/2026, código VB línea por línea)
| Brecha | Estado | Dónde |
|---|---|---|
| Costos fijos sin pasar por Tesorería (sin autorización ni voucher) | Hecho | Costos fijos › Enviar a Tesorería: PG con su OP, se programa, autoriza y paga; la cuota queda pagada sola |
| Descuento en monto sobre el total de la OC/OS (Txtdescuento) | Hecho | Emitir orden › Descuento; antes de la detracción; en el detalle y el PDF |
| Concepto del flujo de efectivo desde Tesorería (flujo_requerimiento) | Hecho | Tesorería › botón de flujo en cada orden, también ya pagada |
| Corregir el monto de una OC/OS (actualizar_monto_requerim) | Hecho | Tesorería › Corregir monto: rehace las cuotas, sin pagos, con motivo |
| Importar estado de cuenta del banco con ingresos (FRMBANCOS) | Pendiente | Falta un estado de cuenta real en Excel para el formato |
| Cuotas mensuales por calendario, proveedor en caja chica, ciudad al importar planilla | Pendiente (menor) | |

## Tercera auditoría (28/10/2026, en pantalla en el .exe + código)
| Pantalla | Hallazgo | Estado |
|---|---|---|
| Mantenimiento de Proveedor | Tipo de cuenta: lista fija CTE./CCI. SOLES/DOLARES | Hecho: lista desplegable (`finance.supplier_account_types`) |
| Mantenimiento de Proveedor | Rubro: 110 rubros fijos en el combo | Hecho: sugeridos (`finance.supplier_categories`) junto con los ya usados |
| Mantenimiento de Proveedor | Banco: catálogo TBANCO (BCP, BCR, BN DETRACCION, CAJA TRUJILLO, INTERBANK…) | Hecho: sugeridos (`payroll.banks`), se puede escribir otro |
| Mantenimiento de Proveedor | "T costo" se ve en pantalla pero el .NET no lo guarda (`Registrar_proveedor` no lo recibe) | No es brecha |
| Registro de requerimiento | Agencia (MOSTRAR_AGENCIA) llena sede y lugar de entrega con su dirección y da la serie (seriereq) | Hecho: Agencia con las sedes de la empresa; serie de esa sede |
| Registro de requerimiento | Área obligatoria ("seleccione el area que solicita") | Hecho |
| Registro de requerimiento | Correo "SU REQUERIMIENTO HA SIDO INGRESADO" al registrar | Hecho: aviso "Requerimiento ingresado" (campana y correo) a quien registra |
| Registro de requerimiento | El correo se llena con el de la agencia | Distinto a propósito: la web usa el del usuario |
| Evaluación | Aprobar / Rechazar con aprobador, fecha y correo; Eliminar = ELIMINADO | Ya estaba (Eliminar = Anular) |
| Evaluación | Un rechazado se puede aprobar después (Button41 no revisa la etapa) | Hecho: "Aprobar de todos modos" en un rechazado |
| Evaluación | Fecha de aprobación elegida a mano y rechazar algo ya aprobado | Distinto a propósito: fecha real del sistema; aprobado ya pasa a cotización |
| Solicitud | SC por proveedor con serie SC + serie de la agencia, condición de pago, observación, recojo/entrega, PDF y correo | Ya estaba (la serie ahora sale de la agencia) |
| Solicitud | Rubro que filtra los proveedores (MOSTRAR_proveedor_rubro) | Hecho: filtro por rubro en la lista de proveedores de la SC |
| Cotización | Banco y cuenta del proveedor elegidos al cotizar (CREAR_COTIZACION @banco @nro_cuenta) | Hecho: "Cuenta para el pago"; Tesorería y la OP muestran solo esa cuenta |
| Programación (FrmReqProg) | Columna "Dias" (negativo = vencida) y filas vencidas resaltadas | Hecho: "Vencida hace N d / Faltan N d" en cada cuota |
| Programación | Cuotas con DateAdd(Month) | Hecho: cada 28–31 días = mensual por calendario (31/01 → 28/02 → 31/03) |
| Tesorería › Bancos (FRMBANCOS) | No es estado de cuenta: carga gastos desde Excel y crea una deuda pendiente por fila (banco elegido no se guarda) | Hecho: Tesorería › Cargar gastos = pago directo por fila, todo o nada, proveedor por RUC de la empresa |
| RR. HH. › Importar Planilla | Ciudad por colaborador; "Dar de Baja" solo lista | Hecho: columna Ciudad ubica en la sede de esa ciudad de la empresa; bajas solo listadas (igual) |
| Caja Chica, Pagos, RR. HH. Programación | Revisados en pantalla | Ya estaban |
| Logística (6), Finanzas (4), Tesorería › Movimientos y Flujo, Administrativos | "MODULO EN CONSTRUCCION" (visto en pantalla) | No es brecha; la web ya tiene kardex, inventario, combustible y flujo de caja |

## Cuarta pasada (29/10/2026): Registro de requerimiento probado en el .exe (RQ 7235, registrado, aprobado y eliminado)
| En el .exe | Web |
|---|---|
| Orden: Código, Fecha Requerimiento, Agencia, Sede requerimiento, # Requerimiento, Área, Colaborador, Correo, Lugar Entrega, Glosa | Mismo orden y nombres; Clasificación, Prioridad, Vehículo y Km en "Datos adicionales" |
| Agencia → sede, lugar de entrega y N.º con la serie de la agencia (RQ-TN01 000001, vista previa) | Igual: N.º previsto (no se reserva) con la serie de la sede |
| Colaborador: lista del personal; trae su correo | Igual (solo personal activo de la empresa) |
| Área: 8 fijas (ADM, OPE, LOG, MAN, VEN, SIS, CON, CHO) | Igual (`finance.requisition_areas`) + departamentos de la empresa |
| Grilla DESCRIPCION, UNID MED, CANT., PLACA / REF. | Mismas columnas y orden (formulario y ficha) |
| Migrar (FrmEXCEL) fuerza "UNIDAD (BIENES)" | La web respeta la unidad del Excel (mejor) |
| + y Crear Prod/Serv: sus paneles quedan tapados en pantallas de 1456 px (defecto del .NET) | Línea nueva y producto por nombre (se crea si no existe) |
| Req Aprobado por: un nombre fijo por empresa en el código | Apoderados activos de la empresa (Apoderados y firmas); aprueba quien tiene el permiso |
| Aprobar / Rechazar / Eliminar con fecha | Igual; Eliminar = anular (queda en el historial) |
| Adjuntar comprobante: imagen / PDF en tblrequerimiento.PDF_SOLI | Adjuntar comprobante (PDF o imagen, privado, columna `attachments`) |
| Buscar: Desde / Hasta | Listado: Desde / Hasta por fecha del requerimiento |
| Ver Solicitud, Ver Cotizaciones, Ver OC, Ver Doc Fiscal, Ver Pago, Explorar PDF | En la ficha: PDF de cada SC, cotización, OC/OS, factura, voucher y comprobante |
| Aprobar se activa con teclado (el clic en el ícono no responde) | — |
| Desde OC en adelante no se probó en el .exe: genera cuotas reales en la programación de pagos | Pendiente de una base de pruebas del .NET |
| Solicitud cotización (RQ 7236, eliminado): Rubro, Prov, lista con casilla y RUC, → / ←, Datos de entrega (Recojo en tienda / Entrega Domicilio), Lugar Entrega, Condición de pago, botones Solicitud, Cotización (imprimir) y Coregir | Ya estaba en la web (SC por proveedor, rubro, entrega, condición, PDF). El registro de la SC en la base real lo bloqueó el control de permisos: no se probó |
| Flujo completo en base de pruebas (RQ 7237 → SC-TN10-000001-2026 → cotización COT-PRUEBA-01 → OC-TN10-000001-2026 por S/ 118 → facturación F001-000123 → aparece en Programación; eliminado y su cuota sale de Programación) | Ver filas siguientes |
| Cotización: proveedor, RUC, banco y cuenta del proveedor, contacto, teléfono, N.º, Aprobado por, fechas, observación, imagen/PDF; **sin monto** (el monto se pone en la OC) | Web: igual más el monto (para comparar cotizaciones) y la cuenta para el pago |
| OC: ORDEN C. / ORDEN S. dan el número; precio por ítem (doble clic), Igv sube el P. unit. 18 %, S/Igv, Descuento, Detracción, Medio pago, Tipo venta, Moneda, cuotas con Generar, Correo proveedor, Recibido por | Web: emitir OC/OS con precio, Igv / S/Igv, descuento, detracción, cuotas, PDF |
| Facturación (pestaña AUTORIZAR PAGO): comprobante, **Autorizado por** (lista por empresa), facturas asociadas, imagen/PDF, flujo efectivo, ver depósitos 1–4 | Web: factura con PDF en la orden; autoriza el apoderado designado en Tesorería; flujo de efectivo; vouchers |
| Programación automática (código): OC/OS al CONTADO → `generar_programacion(Now)`; a CRÉDITO no; planilla (FrmRegrrhh) → Now; costos fijos (FrmReqProg Button37) → fecha de la cuota; pago directo / OP crédito → no | **Hecho** igual: OC/OS contado y planilla programadas hoy, costo fijo en su fecha, crédito y pago directo por programar; Tesorería puede reprogramar |
| Caja Chica (Fcaja_chica): plantilla CAJA_CHICA.xlsx (FECHA, MEDIO PAGO, CLASIFICACION, SERIE, NRO, RUC, GLOSA, RESPONSABLE, NRO RECIBO, MONTO); RUC nuevo → SUNAT (Migo) y alta del proveedor | **Hecho**: la web lee esa plantilla (tipo por la serie F/B/E) y da de alta el proveedor por RUC con Migo de la empresa |
| Migrar ítems: plantilla DETALLE_DOC.xlsx (PRODUCTO, UNIDAD MED, CANTIDAD, CLASIFICACION, PLACA / REF, CODE) | **Hecho**: PLACA / REF se lee |
| Bancos (FRMBANCOS): misma plantilla; deja cada gasto **ya pagado** (CANCELADO, origen BANCOS) | Web: Cargar gastos deja cada uno por la cola de Tesorería (decisión del 27/10: quien autoriza no paga) |
| Registro Proveedor (proveedor 751 creado y eliminado): RUC repetido avisa; **Agregar cuenta no hace nada** en FrmRegProveedor (Button22 sin código) | Web: cuentas con tipo de la lista; RUC único |
| RR. HH. › Planilla: MES/AÑO, Generar (un requerimiento por colaborador), Orden P (OP programada hoy) | Web: Pagos de RR. HH. (importar planilla → Tesorería, programada hoy) |
| Al emitir la OC, la cuota queda **programada sola** (Fecha Progr. = Fecha Venc.) | Ver la fila de programación automática |
| Tesorería › Pagos filtra por la empresa del combo superior (vacío = nada) y por Desde/Hasta | Web: siempre la empresa activa |
| Correos del .NET en FrmReq: solo al campo Correo (ingresado, aprobado, rechazado); el de la OC al proveedor está comentado. FrmRegpagos manda la confirmación de depósito al proveedor | La web avisa a quien pidió y envía la SC/OC y el voucher al proveedor solo cuando se pulsa el botón |

## Quinta pasada (29/10/2026): RR. HH. › Planilla (FrmRegrrhh) en el .exe y en el código

Probado en el .exe con GROUP C & P S.A.C. (1 colaborador), periodo ENERO 2035 para no ocupar un mes real: Generar → requerimiento **7238**; doble clic → Banco y CCI salen de las cuentas del colaborador, monto 1500 → Aceptar ("Sueldos a pagar" 1500); Orden P → **PS-CP-000001-2026**, F. Program = ahora, "Sueldo acumulado" 1500 y "Sueldos a pagar" 0. Reportes → Crystal "LISTADO DE COLABORADORES" (CODIGO, COLABORADOR, DNI, DIRECCION, TELEF., CORREO, BANCO, TIPO, CCI). El 7238 / PS-CP-000001-2026 quedan en la base de prueba: Programación y Requerimientos del .exe solo listan la empresa de la sesión (TURISMO NEGREIROS) y no se pudo eliminar sin volver a iniciar sesión.

| .NET (FrmRegrrhh) | Cómo se alimenta en el .NET | Web (Pagos de RRHH) |
|---|---|---|
| MES / AÑO | combos; la grilla se recarga solo al cambiar el AÑO (`ComboBox10_SelectedIndexChanged`) | **Hecho**: selects Mes (ENERO…DICIEMBRE) y Año; cambiar cualquiera recarga |
| Empresa | combo rosado arriba a la derecha (`ComboBox1`) | la empresa activa del panel (multiempresa); nunca se mezclan |
| Colaboradores / Planilla / Reportes / Requerimientos | pestañas y botones del formulario | **Hecho**: Colaboradores → /employees, Planilla → importar, Reportes → listado de colaboradores, Requerimientos → /requisitions?search=RRHH (cada uno con su permiso) |
| NRO COLABORADORES | `Panelb.Items.Count` | **Hecho**: colaboradores distintos del periodo |
| SUELDO ACUMULADO | suma de "Monto a depositar" al cargar el periodo | **Hecho**: suma de los montos del periodo |
| SUELDOS A PAGAR | suma de las filas aún sin orden de pago | **Hecho, adaptado**: en la web cada fila ya nace con su OP al importar, así que es lo que falta pagar (monto − pagado) |
| # Operación | id del requerimiento | código PL-… del pago |
| Colaborador | `proveedor` | nombre + DNI + concepto |
| Orden Pago | `doc_asoc` (serie OP) | **Hecho**: OP-{PL}-01, con enlace al PDF si puede ver Tesorería |
| F. Prog / F. Pago | `fechaodp` / `MOSTRAR_fpago` | **Hecho**: programada / pagada de la cuota |
| Ciudad / Cargo | `ciudad` / `rubro` del colaborador | **Hecho**: ciudad de la sede / cargo del colaborador |
| Monto a depositar | `monto_pagar` | monto (y lo pagado si es parcial) |
| Banco / CCI | `banco` / `nro_cuenta` | **Hecho**: banco del legajo y CCI (si no hay, la cuenta). Completa para quien opera con ella (`profiles.view_sensitive` o `payroll.export`: RRHH y Contabilidad), ••••••1234 para el resto |
| Generar + Aceptar + Orden P | 3 pasos: crea un RQ por colaborador, se escribe el monto fila por fila, luego emite las OP | un paso: importar la planilla (Excel por DNI) emite las OP ya programadas (decidido antes; misma regla `generar_programacion` con Now) |
| Reportes | Crystal `Crcolaboradores` | **Hecho, sin duplicar**: el reporte que ya existía (`/finance-reports/employees`) ahora trae también Código, Dirección, Tipo de cuenta y CCI; el botón Reportes lo descarga. En el Excel las cuentas van enmascaradas (el archivo sale del sistema) |

Corregido en la web en lugar de copiar el .NET:
- **Periodo por empresa**: `validar_periodo` no filtra por empresa y un periodo generado en una empresa bloquea Generar en las demás. En la web la planilla es por empresa (índice único empresa + colaborador + periodo + concepto); probado: dos empresas con el mismo periodo, cada una con sus totales.
- **Banco y CCI no se pierden**: en el .NET quedan en blanco tras la OP; en la web salen siempre del legajo.
- **Montos**: el .NET deja "01500" si no se borra el 0; la web valida el monto (> 0, separador de miles) al importar.
- **Vista global del Super Admin**: sin empresa elegida, la planilla pide elegir empresa (ver abajo).

Seguridad: la contraseña `sa` del SQL Server está en texto plano en `sisgere_prog.exe.config` (servidor público 190.119.199.58): **cambiarla**. La base `bettson.bak` no se pudo consultar aquí (no hay SQL Server local y el texto de los procedimientos no está legible en el respaldo); no se usó la conexión del .exe.

## Sexta pasada (29/10/2026): reglas de toda la web (empresa, moneda, cuentas)
| Regla | Antes | Ahora |
|---|---|---|
| Una empresa a la vez en las pantallas de dinero | En la vista global del Super Admin, Tesorería, Caja chica, Costos fijos, Flujo de caja, Reportes de finanzas, Pagos de RRHH, Inventario, Combustible y Compras por producto sumaban todas las empresas | Piden la empresa con el selector "¿En qué empresa?" que ya usaban los formularios (`SetWebTenant::SINGLE_COMPANY_ROUTES`, un solo lugar). Los listados sin montos (p. ej. Proveedores) siguen en la vista global |
| Soles y dólares no se suman | Los indicadores de Tesorería (Vencido, Vence en 7 días, Total por pagar, Pagado este mes) sumaban S/ y US$ y lo mostraban como S/ | Un total por moneda de la orden, sin órdenes eliminadas (igual que Flujo de caja, que ya filtraba por moneda) |
| Cuentas de colaboradores | Cada pantalla con su propio criterio y formato | `EmployeeProfile::accountFor`: en pantalla, completa para quien opera con ella (`profiles.view_sensitive` o `payroll.export`) y enmascarada para el resto; Excel y PDF siempre con `EmployeeProfile::mask` (••••••1234). Las cartas de CTS y liquidación al banco mantienen su regla (cuenta completa con `profiles.view_sensitive`) |

Pruebas: `SuperAdminTenantPickerTest::test_money_screens_never_add_up_companies_in_the_global_view`, `TreasuryFlowTest::test_treasury_totals_are_per_currency_and_per_company`, `PayrollPaymentsTest::test_planilla_screen_…` (mismo periodo en dos empresas).
