=== Ferk Topten Connector ===
Contributors: ferk
Tags: woocommerce, payments, gateway
Requires at least: 6.0
Tested up to: 6.5
Stable tag: 0.2.4
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Integración de WooCommerce con la plataforma TopTen para procesar pagos mediante GetNet.

== Descripción ==
Ferk Topten Connector agrega un gateway de pago "GetNet (TopTen)" y sincroniza clientes y carritos entre WooCommerce y la plataforma TopTen.

== Instalación ==
1. Sube la carpeta del plugin a `wp-content/plugins/` o instala el ZIP desde el administrador de WordPress.
2. Activa el plugin desde el menú "Plugins".
3. Configura las credenciales en WooCommerce → TopTen Connector.
4. Habilita el método de pago GetNet (TopTen) en WooCommerce → Ajustes → Pagos.

== Preguntas frecuentes ==
= ¿Necesito cuenta en TopTen? =
Sí, debes solicitar credenciales a tu equipo de TopTen.

= ¿Cómo pruebo la conexión? =
Usa el botón "Testear conexión" en la pestaña de herramientas para verificar la comunicación con la API.

== Changelog ==
= 0.2.4 =
* Añade webhook de confirmación de pago con validación de firma, manejo de retorno y mapeo robusto de estados.
* Muestra las URLs de webhook y retorno en ajustes, agrega botón de copia y permite configurar callback por pago.
* Mejora el metabox de pedidos con último estado, acceso rápido al enlace de pago y acción manual para marcar como pagado.

= 0.2.3 =
* Integra el endpoint PaymentPlacetopay para iniciar sesiones de pago externas con PlaceToPay.
* Añade constructor JsonPedido y mapeo de monedas para completar la información requerida por TopTen.
* Mejora herramientas y metadatos de pedidos en administración para diagnosticar sesiones de pago.

= 0.2.2 =
* Implementa el endpoint AddCartProductExternal para crear carritos en TopTen y reutilizarlos en el checkout.
* Añade utilidades para mapear Prod_Id y atributos de productos hacia TopTen.
* Mejora las herramientas de administración con prueba de creación de carrito y enlaces al backoffice.

= 0.2.0 =
* Versión inicial del conector con integración básica de clientes, carritos y pagos.

== Pruebas manuales ==
=== Probar return handler ===
1. Crea un pedido y finaliza el checkout hasta la redirección externa.
2. Abre manualmente `https://TU-DOMINIO/wp-json/ftc/v1/getnet/return?order_id=123&key=wc_order_key`.
3. Debe redirigirte a la página de "Pedido recibido".

=== Probar webhook sin firma (modo dev) ===
```
curl -X POST "https://TU-DOMINIO/wp-json/ftc/v1/getnet/webhook" \
     -H "Content-Type: application/json" \
     -d '{"Token":"ABC123","IdAdquiria":456,"Carr_Id":789,"Status":"APPROVED"}'
```
Debe ubicar el pedido por Token/IdAdquiria/Cart y marcarlo como pagado.

=== Probar firma del webhook ===
1. Calcula `signature = base64( HMAC_SHA256( raw_body , WEBHOOK_SECRET ) )`.
2. Envía el header `X-Topten-Signature: {signature}` junto al webhook.
3. Si la firma no coincide, el endpoint debe responder 401.
