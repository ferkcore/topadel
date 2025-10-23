=== Ferk Topten Connector ===
Contributors: ferk
Tags: woocommerce, payments, gateway
Requires at least: 6.0
Tested up to: 6.5
Stable tag: 0.2.1
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
= 0.2.1 =
* Integración real con el endpoint NewRegister para crear/obtener usuarios TopTen.
* Asociación automática del usuario TopTen en el flujo del gateway GetNet.
* Herramienta administrativa para probar la creación de usuarios en sandbox.

= 0.2.0 =
* Versión inicial del conector con integración básica de clientes, carritos y pagos.
