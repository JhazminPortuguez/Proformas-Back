# Matriz de casos borde

Anexo de la sección 17 ("Casos borde de los flujos principales"). Para cada caso indica el estado y la prueba que lo verifica.

**Criterio de aceptación (17.12):**
- Todo P0 tiene una prueba en el back y validación en la interfaz.
- Todo P1 tiene una prueba o una justificación escrita.
- Los P2 solo se registran.

**Cómo leer la columna de pruebas:**
- **Back:** `Archivo::prueba`, en `tests/Feature` o `tests/Unit`, sin el prefijo `test_`.
- **Front:** `archivo.test.tsx › "nombre"`, en `Proformas-Front/src`.
- **Capturas:** `borde_NN` y `borde2_NN` están en `C:\VSC\capturas_revision`.

**Estados:**
- **cubierto:** implementado y probado.
- **pendiente:** registrado, sin implementar.
- **decisión de negocio:** espera una definición. Hoy rige lo que dice la fila.

| Estado | Casos |
|---|---|
| cubierto | 94 de 103, entre ellos todos los P0 salvo X-11 |
| decisión de negocio | 7: P-04, P-11, O-16, I-07, X-11, U-05, U-07 |
| cubierto con una decisión pendiente | 2: P-03 (precio 0 en una línea libre) y D-03 (otras monedas) |
| pendiente | 2 (P2): C-05, D-02 |

## 17.1 Reglas transversales

| ID | Módulo | Caso borde | Prioridad | Estado | Test o archivo que lo prueba |
|---|---|---|---|---|---|
| T-01 | Transversal | Usuario inactivo con sesión abierta | P0 | cubierto | `InactiveUserTest::usuario_desactivado_pierde_el_acceso_aunque_tenga_sesion`, `::admin_desactiva_usuario_y_la_sesion_ya_no_entra`; front `permissions.test.ts › "un usuario inactivo no puede hacer nada"` |
| T-02 | Transversal | Cambio de rol durante la sesión | P0 | cubierto | `CasosBordeMatrizTest::cambio_de_rol_durante_la_sesion_usa_el_rol_actual`. El usuario se lee en cada petición; el front decide los permisos con `/me` en cada carga del layout |
| T-03 | Transversal | ID de otra empresa en URL o body | P0 | cubierto | `EmpresaIsolationTest::usuario_de_empresa_2_no_ve_clientes_de_empresa_1`, `ClienteHistorialTest::cliente_de_otra_empresa_devuelve_404`, `CasosBordeMatrizTest::pago_en_cuenta_de_otra_empresa_responde_404`; front: la página muestra "no encontrado" ante un 404 (`errors.test.ts`) |
| T-04 | Transversal | Cliente intenta una ruta interna | P0 | cubierto | `PortalTest::cliente_recibe_403_en_todas_las_rutas_internas`, `AuthorizationTest::cliente_no_accede_a_rutas_internas`; front `permissions.test.ts › "el cliente queda limitado al portal"` |
| T-05 | Transversal | Interno intenta el portal del cliente | P1 | cubierto | `PortalTest::admin_y_operario_no_usan_el_portal` |
| T-06 | Transversal | Sesión o CSRF vencidos | P1 | cubierto | front `client.test.ts › "renueva el CSRF y reintenta una vez cuando recibe 419"`; los formularios redirigen al login ante un 401 o 419 |
| T-07 | Transversal | Doble clic en guardar o confirmar | P0 | cubierto | front `doble-clic.test.tsx` (pagar, cancelar orden, crear cliente, solicitud del portal, aceptar proforma: una sola petición). Back idempotente: `ProformaTest::segunda_aceptacion_es_rechazada`, `CuentaPorCobrarTest::cerrar_orden_genera_una_sola_cuenta…`, `SolicitudTest::no_se_convierte_dos_veces_la_misma_solicitud` |
| T-08 | Transversal | Error de red o timeout | P1 | cubierto | front `client.test.ts › "convierte un fallo de red en ApiError status 0"`, `errors.test.ts › "centraliza el mensaje de red"`; la tabla de usuarios revierte el cambio optimista (`usuarios-table.test.tsx`) |
| T-09 | Transversal | Fecha date-only en zona horaria de Bolivia | P0 | cubierto | `CuentaPorCobrarTest::fecha_de_pago_no_puede_ser_futura…_en_hora_de_bolivia`, `::cuenta_vencida_se_calcula_con_el_dia_de_bolivia`, `InventarioTest::filtro_de_fechas_de_movimientos_usa_el_dia_de_bolivia`, `OrdenServicioTest::el_numero_de_orden_usa_el_anio_de_bolivia`, `DemoSeederTest::demo_seeder_corre_de_madrugada_en_bolivia`; front `date.test.ts` (11 casos) |
| T-10 | Transversal | Payload con campos de otra empresa o mass assignment | P0 | cubierto | `PortalTest::cliente_id_de_la_peticion_se_ignora`, `EmpresaIsolationTest::creacion_autocompleta_empresa_del_usuario_autenticado`, `ProductoTest::no_permite_editar_stock_directamente`, `ProformaTest::put_generico_no_permite_cambiar_estado`; front: los payloads no envían `empresa_id` ni `cliente_id` en el portal (`portal-componentes.test.tsx › "envía descripción e ítems sin cliente…"`) |

## 17.2 Autenticación, registro y permisos

| ID | Módulo | Caso borde | Prioridad | Estado | Test o archivo que lo prueba |
|---|---|---|---|---|---|
| A-01 | Auth | Login con email inexistente o password incorrecto | P1 | cubierto | `AuthTest::login_con_password_invalido_devuelve_401_json` (mensaje genérico) |
| A-02 | Auth | Rate limit de login | P1 | cubierto | `LoginThrottleTest::login_se_bloquea_tras_cinco_intentos_fallidos`; front `errors.test.ts › "usa fallback fijo en 403, 419 y 429"` |
| A-03 | Auth | Registro con email ya existente | P0 | cubierto | `RegistrationTest::registro_rechaza_email_de_usuario_existente`, `::registro_rechaza_el_correo_de_un_cliente_existente`; front: el formulario de registro muestra el 422 junto al campo |
| A-04 | Auth | Registro sin datos mínimos | P1 | cubierto | `RegistrationTest::registro_valida_confirmacion_de_password`, `ValidationMessagesTest::los_errores_de_validacion_se_muestran_en_espanol` |
| A-05 | Auth / Portal | Cliente sin `cliente_id` o vinculado a un cliente inactivo | P0 | cubierto | Decidido: sin `cliente_id` no entra; con cliente inactivo entra y ve lo suyo, pero no envía solicitudes. `PortalTest::cliente_sin_cliente_vinculado_recibe_403`, `::cliente_inactivo_ve_sus_datos_pero_no_envia_solicitudes`; front `portal-componentes.test.tsx › "bloqueado: el botón queda deshabilitado y no envía"`, captura borde2 del portal |
| A-06 | Usuarios | Último admin intenta desactivarse o eliminarse | P0 | cubierto | Responde 422 (no 409) con el motivo. `UserGuardTest::no_se_puede_degradar_al_ultimo_admin`, `::un_admin_inactivo_no_cuenta_como_reemplazo_del_ultimo_admin_activo`, `::admin_no_puede_desactivar_su_propia_cuenta`; front `usuarios-table.test.tsx` (muestra el motivo del 422) |
| A-07 | Auth | `?next=` externo o malicioso | P0 | cubierto | Solo el front redirige: la API de login no acepta destino. Front `redirect.test.ts › "rechaza destinos externos o maliciosos"` (`//host`, `/\host`, `javascript:`, tab, salto de línea…) y `› "no lleva a la API, a sanctum ni de vuelta al login"` |
| A-08 | Auth | Usuario autenticado abre login o registro | P2 | cubierto | `src/app/(auth)/login/page.tsx` redirige a la portada del rol si ya hay sesión |

## 17.3 Clientes

| ID | Módulo | Caso borde | Prioridad | Estado | Test o archivo que lo prueba |
|---|---|---|---|---|---|
| C-01 | Clientes | Eliminar cliente con proformas, órdenes o CxC | P0 | cubierto | `DeleteGuardsTest::no_se_elimina_cliente_con_proformas`, `::admin_puede_eliminar_cliente_sin_proformas`; front: la tabla muestra el motivo del 409 (`mensajeDeError`) |
| C-02 | Clientes | NIT/CI o email duplicado | P1 | cubierto | Decidido: no se repite el NIT/CI en la empresa (vacío y "0" no cuentan) y el mensaje nombra al cliente existente. `ClienteNitDuplicadoTest` (4 pruebas); front `cliente-form-drawer.test.tsx › "muestra junto al campo el cliente que ya tiene ese NIT/CI"`. El email del portal no se duplica: `RegistrationTest::registro_rechaza_el_correo_de_un_cliente_existente` |
| C-03 | Clientes | Cliente inactivo en nueva proforma u orden | P1 | cubierto | Decidido: bloquear. `ProformaCasosBordeTest::no_se_crean_proformas_para_un_cliente_inactivo`, `::no_se_convierte_una_solicitud_de_un_cliente_inactivo`; front `casos-borde-proformas.test.tsx › "una proforma nueva no ofrece clientes inactivos"`. Desactivar con trabajo en curso avisa: `ClienteEnCursoTest`, `cliente-form-drawer.test.tsx › "avisa con los números…"` |
| C-04 | Clientes | Cliente sin historial | P2 | cubierto | `ClienteHistorialTest::resumen_de_cliente_sin_historial_devuelve_ceros_y_claves_completas`; front `cliente-tabs.test.tsx › "ofrece un estado vacío claro…"` |
| C-05 | Clientes | Dos ediciones concurrentes del mismo cliente | P2 | pendiente | Hoy gana la última escritura y queda `updated_by`. El versionado se registra para una fase posterior |
| C-06 | Clientes | Búsqueda con ñ o acentos y paginación | P2 | cubierto | `ClienteTest::busqueda_ignora_acentos_y_mayusculas` |

## 17.4 Proformas

| ID | Módulo | Caso borde | Prioridad | Estado | Test o archivo que lo prueba |
|---|---|---|---|---|---|
| P-01 | Proformas | Proforma sin ítems | P0 | cubierto | `CasosBordeMatrizTest::proforma_sin_items_o_con_cantidades_invalidas_es_422`; front `proforma-form.test.tsx › "detecta cliente e ítems faltantes antes de llamar al backend"` |
| P-02 | Proformas | Cantidad 0, negativa o con más de 3 decimales | P0 | cubierto | `CasosBordeMatrizTest::proforma_sin_items_o_con_cantidades_invalidas_es_422`, `ProformaTest::cantidad_debe_ser_mayor_que_cero`; front `proforma-form.test.tsx` (validación local antes de enviar) |
| P-03 | Proformas | Precio negativo o cero en línea libre | P0 | cubierto / decisión de negocio | Negativo: 422 (`CasosBordeMatrizTest::linea_libre_con_precio_negativo_es_422`, front `proforma-form.test.tsx`). Precio 0: hoy se permite; la proforma completa en Bs 0 no se envía (P-08). Falta decidir si una línea en Bs 0 se permite |
| P-04 | Proformas | Servicio o material repetido | P1 | decisión de negocio | Hoy se permite sin aviso (`ProformaItemTest::permite_dos_lineas_del_mismo_producto`). Falta decidir: permitir con advertencia o bloquear |
| P-05 | Proformas | Descuento mayor al subtotal | P0 | cubierto | `ProformaTest::descuento_no_puede_superar_subtotal`; front: el 422 se muestra junto al campo de descuento (`use-form-errors`) |
| P-06 | Proformas | Impuesto fuera de rango o negativo | P0 | cubierto | `ProformaTest::impuesto_tasa_fuera_de_rango_es_422`; front: error por campo del 422 |
| P-07 | Proformas | Validez anterior a la emisión | P1 | cubierto | `ProformaTest::fecha_validez_no_puede_ser_anterior_a_la_emision` |
| P-08 | Proformas | Enviar proforma en Bs 0 | P0 | cubierto | `ProformaTest::no_se_envia_una_proforma_con_total_cero`, `::un_descuento_que_deja_el_total_en_cero_tampoco_se_envia`; front `envio-total-cero.test.tsx` (4 pruebas) |
| P-09 | Proformas | Enviar, editar o aceptar en estado incorrecto | P0 | cubierto | `ProformaTest::no_se_edita_contenido_de_proforma_enviada`, `::no_se_puede_enviar_una_proforma_ya_aceptada`, `::put_generico_no_permite_cambiar_estado`; front `casos-borde-proformas.test.tsx › "si el back rechaza, el aviso dice el motivo…"` |
| P-10 | Proformas | Aceptar o rechazar dos veces | P0 | cubierto | `ProformaTest::segunda_aceptacion_es_rechazada`; front `doble-clic.test.tsx › "confirmar aceptar proforma: una sola petición"` |
| P-11 | Proformas | Cliente intenta aceptar desde el portal | P1 | decisión de negocio | Hoy el portal no acepta ni rechaza (`PortalTest::el_portal_no_acepta_ni_rechaza`); solo el admin y el operario registran la aceptación. Es la decisión 17.11-2 |
| P-12 | Proformas | Eliminar proforma con orden o CxC | P0 | cubierto | `DeleteGuardsTest::no_se_elimina_proforma_fuera_de_borrador` (una proforma con orden ya no está en borrador); front: el botón eliminar solo existe en borradores (`acciones_disponibles`) |
| P-13 | Proformas | Orden desde proforma no aceptada o con orden vigente | P0 | cubierto | `OrdenServicioTest::no_crea_orden_desde_proforma_borrador_enviada_o_rechazada`, `::no_duplica_orden_vigente…`; front `crear-orden-dialog.test.tsx` |
| P-14 | Proformas | Catálogo eliminado o desactivado después del snapshot | P1 | cubierto | `OrdenServicioTest::el_snapshot_de_items_no_cambia_si_se_modifica_la_proforma`, `ProformaCasosBordeTest::proforma_que_ya_tiene_catalogo_desactivado_se_sigue_editando_sin_cambiarlo`, `DeleteGuardsTest::no_se_elimina_servicio_incluido_en_proformas` |
| P-15 | Proformas | Proforma vencida | P1 | cubierto | Decidido: no se envía si la validez ya pasó (hora de Bolivia); aceptar una enviada y vencida se permite con aviso. `ProformaCasosBordeTest::no_se_envia_una_proforma_con_la_validez_vencida_en_hora_de_bolivia`; front `casos-borde-proformas.test.tsx › "deshabilita Enviar con el motivo…"`, `› "se permite, pero el diálogo avisa la fecha en que venció"` |
| P-16 | Proformas | Dos usuarios generan número a la vez | P0 | cubierto | `ProformaTest::correlativo_es_secuencial_por_anio`. El correlativo se toma con `lockForUpdate` sobre `proforma_sequences` dentro de la transacción (`GenerarNumeroProforma`). Una carrera real no se puede reproducir en PHPUnit; el bloqueo equivalente de pagos sí se prueba en PostgreSQL (`CuentaPorCobrarTest::postgres_registrar_pago_bloquea_la_cuenta_con_for_update`) |
| P-17 | Proformas | Redondeo por línea frente al total | P0 | cubierto | `DineroTest` (6 pruebas, sin floats), `ProformaItemTest::cantidad_fraccionaria_redondea_total_de_linea_a_dos_decimales`; el total lo calcula el servidor (`CalcularTotalesProforma`) |
| P-18 | Proformas | PDF sin logo, empresa incompleta o texto largo | P1 | cubierto | `ProformaPdfTest::los_datos_del_cliente_no_dejan_un_separador_suelto`, `::el_pie_de_una_aceptada_no_deja_espacio_antes_del_punto`, `ProformaItemTest::linea_libre_admite_descripcion_larga`, `EmpresaConfiguracionTest::el_logo_aparece_en_el_pdf_de_la_proforma` |

## 17.5 Órdenes de servicio

| ID | Módulo | Caso borde | Prioridad | Estado | Test o archivo que lo prueba |
|---|---|---|---|---|---|
| O-01 | Órdenes | Orden desde proforma no aceptada | P0 | cubierto | `OrdenServicioTest::no_crea_orden_desde_proforma_borrador_enviada_o_rechazada`; front: "Crear orden" solo aparece en aceptadas (`proformas-table.test.tsx`) |
| O-02 | Órdenes | Segunda orden vigente para la misma proforma | P0 | cubierto | `OrdenServicioTest::no_duplica_orden_vigente_y_permite_crear_otra_si_la_anterior_fue_cancelada`; front `crear-orden-dialog.test.tsx` |
| O-03 | Órdenes | Operario inexistente, inactivo o de otra empresa | P0 | cubierto | `OrdenServicioTest::no_asigna_usuario_cliente_inactivo_de_otra_empresa_o_inexistente`; front: el selector solo lista operarios activos |
| O-04 | Órdenes | Reasignar una orden ya asignada | P1 | cubierto | Decidido: el admin reasigna una Asignada (no En proceso) a otro operario activo; queda en el historial. `OrdenServicioTest::admin_reasigna_una_orden_asignada_y_queda_en_el_historial`; front `orden-reasignar.test.tsx` (3 pruebas) |
| O-05 | Órdenes | Iniciar sin operario | P1 | cubierto | `OrdenServicioTest::transiciones_invalidas_y_saltos_de_estado_son_rechazados`, `EstadoOrdenTest::no_permite_saltos_ni_reactivacion` |
| O-06 | Órdenes | Finalizar sin iniciar o cerrar sin finalizar | P0 | cubierto | `OrdenServicioTest::transiciones_invalidas_y_saltos_de_estado_son_rechazados`, `EstadoOrdenTest::transiciones_permitidas_del_flujo`; front: las acciones salen de `acciones_disponibles` (`orden-actions.test.tsx`) |
| O-07 | Órdenes | Cancelar una orden finalizada o cerrada | P0 | cubierto | `OrdenServicioTest::transiciones_invalidas_y_saltos_de_estado_son_rechazados`; front: "Cancelar" no aparece fuera de los estados activos (`tooltips-listados.test.tsx`) |
| O-08 | Órdenes | Reprogramar una orden cerrada o cancelada, o con fecha inválida | P1 | cubierto | `OrdenServicioTest::no_se_reprograma_una_orden_en_proceso_finalizada_o_cancelada`, `::reprogramar_valida_la_fecha`; front `orden-reprogramar.test.tsx` |
| O-09 | Órdenes | Consumir servicio, línea libre o material sin producto | P0 | cubierto | `ConsumoMaterialesTest::no_consume_lineas_de_servicio_libre_ni_orden_sin_pendientes`; front `consumir-materiales-dialog.test.tsx › "solo lista materiales con pendiente…"` |
| O-10 | Órdenes | Consumir más de lo pendiente o sin stock | P0 | cubierto | `ConsumoMaterialesTest::rechaza_exceso_duplicados_cantidad_cero_y_stock_insuficiente`; front `consumir-materiales-dialog.test.tsx › "no permite consumir más de lo pendiente"`, `› "advierte cuando el stock actual no alcanza"` |
| O-11 | Órdenes | Consumir dos veces la misma línea | P0 | cubierto | `ConsumoMaterialesTest::consumo_parcial_se_acumula_y_solo_marca_consumido_al_completar`, `::rechaza_exceso_duplicados…` |
| O-12 | Órdenes | Consumir en una orden cancelada o cerrada | P0 | cubierto | `ConsumoMaterialesTest::rechaza_orden_cancelada_cerrada_pendiente_o_asignada`; front: "Consumir" sale de `acciones_disponibles` |
| O-13 | Órdenes | Varias líneas del mismo producto | P1 | cubierto | `ConsumoMaterialesTest::consume_multiples_materiales_de_varios_productos`, `::consumo_explicito_por_linea_y_productos_se_bloquean_en_orden_ascendente` |
| O-14 | Órdenes | Cancelar con consumo ya realizado | P0 | cubierto | Decidido: lo devuelto vuelve al stock (DEVOLUCION, al costo del consumo original) y lo no devuelto puede cobrarse (CxC CANCELACION). `CancelarOrdenConMaterialesTest` (9 pruebas, incluida la de todo o nada); front `cancelar-orden-dialog.test.tsx` (7 pruebas); capturas `borde_08`–`borde_18` |
| O-15 | Órdenes | Orden con total 0 al cerrar | P1 | cubierto | Regla actual: se genera la cuenta ya PAGADA con saldo 0. `CuentaPorCobrarTest::cuenta_de_orden_sin_lineas_nace_pagada_con_saldo_cero` |
| O-16 | Órdenes | Varios operarios por orden | P2 | decisión de negocio | Hoy hay un responsable por orden. Es la decisión 17.11-7 |

## 17.6 Inventario

| ID | Módulo | Caso borde | Prioridad | Estado | Test o archivo que lo prueba |
|---|---|---|---|---|---|
| I-01 | Inventario | Entrada con cantidad 0 o negativa | P0 | cubierto | `InventarioTest::entrada_rechaza_cantidad_invalida_y_producto_de_otra_empresa`; front `stock-dialogs.test.tsx › "valida la cantidad antes de llamar a la API"` |
| I-02 | Inventario | Costo de entrada negativo | P0 | cubierto | `CasosBordeMatrizTest::entrada_con_costo_negativo_es_422`; front `stock-dialogs.test.tsx › "muestra el 422 junto al campo…"` |
| I-03 | Inventario | Ajuste igual al stock actual | P1 | cubierto | `InventarioTest::ajuste_exige_motivo_rechaza_delta_cero_y_stock_negativo` |
| I-04 | Inventario | Ajuste que deja stock negativo | P0 | cubierto | `InventarioTest::ajuste_exige_motivo_rechaza_delta_cero_y_stock_negativo`, `::restricciones_check_de_postgresql`; front `stock-dialogs.test.tsx › "envía el stock final y el motivo"` (validación local) |
| I-05 | Inventario | Entradas o consumos concurrentes | P0 | cubierto | Bloqueo de producto en orden ascendente: `ConsumoMaterialesTest::consumo_explicito_por_linea_y_productos_se_bloquean_en_orden_ascendente`; CHECK `stock >= 0` en PostgreSQL (`InventarioTest::restricciones_check_de_postgresql`) |
| I-06 | Inventario | Consumo que deja stock en 0 | P1 | cubierto | `ConsumoMaterialesTest::consumo_total_descuenta_stock_registra_movimiento_y_evento`; aparece en stock bajo (`InventarioTest::listado_busca_con_acentos_filtra_bajo_stock_y_pagina`) |
| I-07 | Inventario | Producto con stock mínimo 0 | P2 | decisión de negocio | Regla actual: es bajo stock si `stock_actual <= stock_minimo`, así que con mínimo 0 y stock 0 cuenta como bajo. Es la decisión 17.11-8 |
| I-08 | Inventario | Eliminar producto con movimientos | P0 | cubierto | `ProductoTest::no_elimina_producto_con_movimientos`, `DeleteGuardsTest::no_se_elimina_producto_incluido_en_proformas`; front: la tabla muestra el motivo del 409 |
| I-09 | Inventario | Editar stock por el CRUD de producto | P0 | cubierto | `ProductoTest::no_permite_editar_stock_directamente`; front: el formulario de producto no tiene campo de stock (solo entrada y ajuste) |
| I-10 | Inventario | Consumo desde una orden cerrada o cancelada | P0 | cubierto | `ConsumoMaterialesTest::rechaza_orden_cancelada_cerrada_pendiente_o_asignada` |

## 17.7 Cuentas por cobrar y pagos

| ID | Módulo | Caso borde | Prioridad | Estado | Test o archivo que lo prueba |
|---|---|---|---|---|---|
| X-01 | Cobros | Cerrar dos veces la misma orden | P0 | cubierto | `CuentaPorCobrarTest::cerrar_orden_genera_una_sola_cuenta_con_total_vencimiento_y_secuencia` (generar otra vez no duplica); front: "Cerrar" desaparece con `cerrada_at` (`orden-actions.test.tsx`) |
| X-02 | Cobros | Orden con total 0 al cerrar | P1 | cubierto | `CuentaPorCobrarTest::cuenta_de_orden_sin_lineas_nace_pagada_con_saldo_cero` |
| X-03 | Cobros | Pago 0, negativo o mayor al saldo | P0 | cubierto | `CuentaPorCobrarTest::rechaza_sobrepago_montos_invalidos_metodo_invalido_y_pago_en_cuenta_pagada`, `::postgres_rechaza_saldo_mayor_al_monto_total`; front `pago-dialogs.test.tsx › "exige un monto mayor a cero…"`, `› "no permite superar el saldo pendiente"` |
| X-04 | Cobros | Pago sobre una cuenta PAGADA | P0 | cubierto | `CuentaPorCobrarTest::rechaza_sobrepago…_y_pago_en_cuenta_pagada`; front: "Registrar pago" no aparece en cuentas pagadas (`puedeRegistrarPago`) |
| X-05 | Cobros | Dos pagos concurrentes | P0 | cubierto | `CuentaPorCobrarTest::postgres_registrar_pago_bloquea_la_cuenta_con_for_update`; front `doble-clic.test.tsx › "registrar pago: una sola petición"` |
| X-06 | Cobros | Anular un pago ya anulado | P0 | cubierto | `CuentaPorCobrarTest::operario_registra_pagos_pero_solo_admin_anula_con_auditoria` (el segundo intento da 422); front `pago-dialogs.test.tsx › "exige motivo y anula el pago"` |
| X-07 | Cobros | Operario intenta anular un pago | P0 | cubierto | `CuentaPorCobrarTest::operario_registra_pagos_pero_solo_admin_anula_con_auditoria`; front `pago-dialogs.test.tsx › "no ofrece anular a quien no es admin"` |
| X-08 | Cobros | Anular el último pago | P0 | cubierto | `CuentaPorCobrarTest::operario_registra_pagos…` (vuelve a PENDIENTE), `::anular_pago_parcial_recalcula_estado_parcial` |
| X-09 | Cobros | Vencimiento hoy o ayer | P1 | cubierto | `CuentaPorCobrarTest::cuenta_vencida_se_calcula_con_el_dia_de_bolivia`, `ReporteCobrosTest::por_defecto_cobrado_del_mes_y_saldos_por_antiguedad_a_hoy` (vence hoy = al día) |
| X-10 | Cobros | Pago con fecha futura o método inválido | P1 | cubierto | `CuentaPorCobrarTest::fecha_de_pago_no_puede_ser_futura_ni_anterior_a_la_emision_en_hora_de_bolivia`, `::rechaza_sobrepago…_metodo_invalido…`; front `fecha-pago.test.tsx` (4 pruebas); capturas `borde_01`, `borde_02` |
| X-11 | Cobros | Cancelar o reembolsar una CxC | P0 | decisión de negocio | No hay flujo para anular una cuenta ni para reembolsar; hoy solo se anulan pagos. Es la decisión 17.11-5 |
| X-12 | Cobros | Pago en cuenta de otra empresa o cliente | P0 | cubierto | `CasosBordeMatrizTest::pago_en_cuenta_de_otra_empresa_responde_404`, `CuentaPorCobrarTest::cliente_no_accede_al_modulo_de_cuentas_por_cobrar`; front: el detalle muestra "no encontrado" ante un 404 |

## 17.8 Reportes

| ID | Módulo | Caso borde | Prioridad | Estado | Test o archivo que lo prueba |
|---|---|---|---|---|---|
| R-01 | Reportes | Rango sin datos | P2 | cubierto | `ReporteOrdenesTest::rango_de_fechas_explicito_y_rango_vacio`, `ReporteServiciosProformasTest::rango_vacio_devuelve_ceros_y_tasa_nula`; front `reporte-*.test.tsx › "sin … muestra ceros y el mensaje vacío"` |
| R-02 | Reportes | Rango invertido | P1 | cubierto | `ReportesAccesoTest::rango_de_mas_de_un_anio_o_invertido_se_rechaza`; front `reporte-ordenes.test.tsx › "con el rango al revés avisa…"` |
| R-03 | Reportes | Rango demasiado amplio | P1 | cubierto | Decidido: 1 año como máximo. `ReportesAccesoTest::rango_de_mas_de_un_anio_o_invertido_se_rechaza` (5 reportes, JSON y CSV); front `reportes.test.ts › "permite hasta 1 año y rechaza un día más"` |
| R-04 | Reportes | Canceladas, pagos anulados o registros inactivos | P0 | cubierto | `DashboardTest::pagos_anulados_y_de_meses_anteriores_no_suman…`, `::orden_cancelada_no_suma_en_servicios_mas_solicitados`, `ClienteHistorialTest::servicios_pagina_y_ordena_excluyendo_ordenes_canceladas`; el consumo es neto de devoluciones (`CancelarOrdenConMaterialesTest::cancela_devolviendo_una_parte…`); front `reporte-*.test.tsx` muestra lo que entrega el back |
| R-05 | Reportes | CSV con `=`, `+`, `-`, `@` al inicio | P0 | cubierto | `app/Support/Csv.php::celda()`. `CsvTest::antepone_apostrofo_a_texto_que_empieza_como_formula`, `::no_toca_numeros_ni_texto_normal`, `ReporteCobrosTest::csv_neutraliza_formulas_en_textos_del_cliente`. Front: el CSV se genera en el back; el enlace solo lo descarga |
| R-06 | Reportes | CSV con acentos, ñ y saltos de línea | P1 | cubierto | UTF-8 con BOM y `fputcsv` con comillas: `ReporteCobrosTest::exporta_las_cuentas_con_saldo_en_csv` ("Más de 90 días", "Cancelación"). Un salto de línea dentro de una celda queda entre comillas por `fputcsv` |
| R-07 | Reportes | Usuario sin permiso abre un reporte | P0 | cubierto | `ReportesAccesoTest::el_operario_recibe_403`, `::el_cliente_recibe_403`, `::sin_sesion_recibe_401`; front `reportes.test.ts` (el menú solo muestra Reportes al admin) |
| R-08 | Reportes | Datos de otra empresa en los filtros | P0 | cubierto | `CasosBordeMatrizTest::filtro_de_reporte_con_cliente_de_otra_empresa_es_422` (los 5 reportes), `ReporteOrdenesTest::valida_el_rango_y_los_filtros`, `ReportesAccesoTest::el_filtro_de_clientes_solo_envia_id_nombre_y_nit`; front: el filtro solo ofrece clientes de la empresa |

## 17.9 Portal del cliente

| ID | Módulo | Caso borde | Prioridad | Estado | Test o archivo que lo prueba |
|---|---|---|---|---|---|
| U-01 | Portal | Solicitud o proforma de otro cliente por ID | P0 | cubierto | `PortalTest::detalle_y_pdf_de_otro_cliente_responden_404`, `::listados_solo_traen_datos_del_propio_cliente`; front: el detalle del portal muestra "no encontrado" ante un 404 |
| U-02 | Portal | PDF ajeno | P0 | cubierto | `PortalTest::detalle_y_pdf_de_otro_cliente_responden_404`, `ProformaPdfTest::cliente_no_puede_descargar_pdf` |
| U-03 | Portal | Cliente sin vínculo o inactivo | P0 | cubierto | Igual que A-05 (decidido: el inactivo ve lo suyo y no envía solicitudes). `PortalTest::cliente_inactivo_ve_sus_datos_pero_no_envia_solicitudes`, `::cliente_sin_cliente_vinculado_recibe_403`; front `portal-componentes.test.tsx › "si el back responde 403, explica que la cuenta está inactiva"` |
| U-04 | Portal | Solicitud sin ítems o con cantidades inválidas | P1 | cubierto | `PortalTest::valida_la_solicitud`; front `portal-componentes.test.tsx › "valida antes de enviar"`, `› "muestra los errores del servidor junto al campo"` |
| U-05 | Portal | Cliente intenta aceptar sin estar habilitado | P1 | decisión de negocio | Hoy no hay flujo de aceptación en el portal (`PortalTest::el_portal_no_acepta_ni_rechaza`). Ligado a la decisión 17.11-2 |
| U-06 | Portal | Portal sin órdenes ni estado de cuenta | P2 | cubierto | El portal muestra solicitudes y proformas, sin enlaces a módulos que no tiene (`portal-componentes.test.tsx › "muestra el menú del portal…"`) |
| U-07 | Portal | Formulario público sin rate limit | P1 | decisión de negocio | No existe un formulario público: hace falta cuenta, y el registro está limitado a 5 por minuto (`RegistrationTest::registro_esta_limitado_a_cinco_por_minuto_por_ip`). Las solicitudes también tienen límite (`PortalTest::limita_el_envio_de_solicitudes`). Un formulario sin cuenta queda para cuando haya antispam |
| U-08 | Portal | La sesión expira durante una solicitud | P1 | cubierto | Front `client.test.ts › "renueva el CSRF y reintenta una vez cuando recibe 419"`; con 401 el formulario lleva al login. El back no duplica: el límite y la validación por solicitud impiden un envío doble (`doble-clic.test.tsx › "enviar solicitud del portal…"`) |

## 17.10 Dashboard y UI

| ID | Módulo | Caso borde | Prioridad | Estado | Test o archivo que lo prueba |
|---|---|---|---|---|---|
| D-01 | Dashboard | Mes sin movimientos | P1 | cubierto | `DashboardTest::operario_accede_al_dashboard_y_el_entorno_vacio_devuelve_ceros`; front `dashboard.test.ts`, `dashboard-view.test.tsx` (ceros y estados vacíos) |
| D-02 | Dashboard | Cambio de mes mientras se visualiza | P2 | pendiente | Los datos corresponden a la consulta: el mes se calcula en hora de Bolivia al cargar (`DashboardTest`). No se refresca solo al cambiar de mes |
| D-03 | Dashboard | Monedas mezcladas | P0 | cubierto / decisión de negocio | Hoy solo se crean proformas en BOB (`ProformaTest::solo_se_crean_proformas_en_bolivianos`, `::no_se_cambia_la_moneda…`), así que no se mezclan monedas. Una moneda adicional es la decisión 17.11-9 |
| D-04 | UI | Doble submit o refresh durante una mutación | P1 | cubierto | Front `doble-clic.test.tsx` (5 pruebas); todos los botones de guardar, enviar, confirmar, pagar y cancelar se deshabilitan con `pending` |
| D-05 | UI | Error 422 con un drawer abierto | P1 | cubierto | Front `use-form-errors.test.tsx › "cuenta campos y enfoca el primero inválido…"`, `pago-dialogs.test.tsx › "muestra el 422 junto al monto y enfoca el campo"`, `stock-dialogs.test.tsx › "muestra el 422 junto al campo y enfoca la cantidad"` |
| D-06 | UI | 375 px con tabla, tablero o diálogo | P1 | cubierto | Tarjetas en el celular en vez de tablas (`ordenes-table.test.tsx › "muestra el NIT/CI también en las tarjetas del celular"`, `cliente-tabs.test.tsx`); capturas `borde_18` y `borde2_*` en el celular. Cuentas por cobrar cabe sin desplazamiento desde 1280 px (`cobros-table.test.tsx`, captura borde2 a 1280 px) |
| D-07 | UI | Teclado y lectores de pantalla | P1 | cubierto | Front `use-form-errors.test.tsx` (foco), tooltips por foco de teclado (`tooltips-listados.test.tsx`), anuncios con `useAnnouncer`; Playwright `e2e/interno.spec.ts` falla si React Aria avisa de controles sin etiqueta |

## Decisiones que esperan una definición (17.11)

| # | Decisión | Estado |
|---|---|---|
| 1 | Proforma vencida | Decidida: no se envía; aceptar una enviada sí, con aviso |
| 2 | Aceptación de proforma (admin, operario o cliente desde el portal) | Pendiente (P-11, U-05). Hoy aceptan el admin y el operario |
| 3 | Materiales consumidos: cobrables o costo interno | Decidida para la cancelación (se cobra lo no devuelto). En el flujo normal se cobran porque están en la proforma |
| 4 | Cancelación con consumo | Decidida: DEVOLUCION y cobro CANCELACION (O-14) |
| 5 | Cancelar o reembolsar una CxC o un pago | Pendiente (X-11). Hoy solo se anulan pagos |
| 6 | IVA incluido o discriminado; servicios exentos | Pendiente. Hoy hay una tasa por proforma (13 % por defecto) |
| 7 | Varios operarios por orden | Pendiente (O-16) |
| 8 | Stock mínimo 0 como bajo stock | Pendiente (I-07) |
| 9 | Solo BOB o varias monedas | Pendiente (D-03). Hoy solo BOB |
| 10 | Orden directa sin proforma aceptada | Pendiente. Hoy no se permite (O-01) |
