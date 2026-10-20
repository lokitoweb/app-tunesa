# Estándares de Diseño UI para Módulos ERP (Grupo Negreiros / Tunesa)

Este documento define la regla de diseño y experiencia de usuario que debe aplicarse en cada módulo del sistema:

## 1. Cabecera y Barra de Acciones Unificada (Toolbar)
* **Alineación horizontal en una sola fila**: La cabecera (`.company-header-bar`) ubica a la izquierda el título (`h2`) y su subtítulo descriptivo.
* **Filtros y botón principal integrados**: A la derecha, en una sola línea horizontal (`flex-wrap: nowrap;`):
  1. **Buscador integrado**: Input de búsqueda con ícono de lupa posicionado adentro (`.company-search-wrapper`), esquinas redondeadas continuas, sin recuadros ni bordes partidos.
  2. **Selector de filtros**: Select de altura estándar (38px) con borde `#cbd5e1`.
  3. **Botón de acción principal**: Botón primario (`+ Nuevo / Nueva...`) con ícono y texto compacto al lado de los filtros.
* **Prohibido**: Tarjetas gigantes independientes para una simple barra de filtros que ocupen franjas verticales enteras.

## 2. Tarjetas de Resumen KPI
* Fila de 4 tarjetas ejecutivas (`.company-kpi-card`) con:
  * Contenedor de ícono cuadrado con esquinas redondeadas (`44x44px`), fondo suave (`rgba(...)`) y color distintivo.
  * Cifra numérica en negrita (`1.4rem`) y etiqueta en mayúsculas pequeñas (`0.75rem`).
  * Micro-interacción `hover` con ligera elevación (`translateY(-2px)`).

## 3. Tablas y Botones de Acción
* **Badges y Etiquetas**: Radio sutil de 6px (`rounded-2`), nunca píldoras ovaladas exageradas (`rounded-pill`).
* **Botones de acción en filas**: Utilizar botones de ícono compactos (`.btn-table-action`, 32x32px, con esquinas redondeadas de 8px, borde `#e2e8f0`, ícono centrado y tooltip). Sin botones de texto toscos que rompan la densidad de la tabla.
* **Identificadores corporativos / avatares**: Cajas cuadradas con esquinas redondeadas (`.company-avatar-box`, 38x38px) para logotipos o iniciales.

## 4. Modal / Diálogo Expandido de Edición y Creación
* **Tamaño**: `modal-xl` centrado (`modal-dialog-centered modal-dialog-scrollable`).
* **Cabecera**: Ícono representativo en caja estilizada (42x42px), título en negrita, subtítulo gris explicativo y botón de cierre (`btn-close`).
* **Navegación por Pestañas Segmentadas (`.modal-segmented-nav`)**:
  * Contenedor estilo pill/segmented control en `#f1f5f9` con padding de 4px y border-radius de 12px.
  * Pestañas individuales con border-radius de 8px.
  * Pestaña activa con fondo blanco `#ffffff`, texto azul primario `#0284c7`, peso `700` y sombra sutil `box-shadow: 0 1px 4px rgba(0,0,0,0.08)`.
* **Cuerpo Organizado en Tarjetas de Sección (`.modal-section-card`)**:
  * Título de sección (`.modal-section-title`) con ícono primario y texto en mayúsculas sutiles (`letter-spacing: 0.04em`).
  * Fondos de tarjeta `#f8fafc` con borde limpio `#e2e8f0` y espaciado consistente.
  * Inputs agrupados con addons integrados (`.input-domain-wrapper`), evitando separaciones rotas.
* **Botonera Inferior (`.modal-company-footer`)**:
  * Siempre fija en la parte inferior con fondo blanco y borde superior `#e2e8f0`.
  * Botones alineados a la derecha: `Cancelar` (neutro con borde suave) y `Guardar / Crear` (primario destacado con ícono de check o plus).
