# Changelog — block_openaiagent

Todos los cambios relevantes de este plugin se documentan en este archivo.

El formato sigue [Keep a Changelog](https://keepachangelog.com/es/1.0.0/) y el proyecto
usa [Versionado Semántico](https://semver.org/lang/es/).

---

## [4.15.0] — 2026-08-25

Versión de preparación para la publicación en Moodle Marketplace. No cambia el
comportamiento del asistente: cambia lo que se puede evaluar sin licencia, lo que
viaja en el paquete y lo que declara el plugin sobre sí mismo.

### Añadido

- **Tests Behat.** Cuatro escenarios en `tests/behat/`: el bloque aparece con su
  nombre para profesor y para alumno, sin clave de proveedor de IA el bloque dice
  que no está configurado en vez de ofrecer un chat, y la página de ajustes
  muestra el campo de licencia. Sin `@javascript`: usan el generador de bloques,
  así que no dependen de un navegador con JS.
- **Icono propio del bloque** (`pix/icon.svg`).
- **`Example context` en `templates/block.mustache`**, que es lo que pedía el
  linter de plantillas de Moodle.

- **Periodo de evaluación de 15 días.** Una instalación nueva arranca con el
  asistente **completamente funcional sin ninguna clave de licencia** durante 15
  días. La ventana se abre sola al instalar (y al actualizar, para las
  instalaciones que ya existían), se cuenta con una marca de tiempo local en
  `config_plugins` y **no consulta nada externo**: el plugin sigue sin llamar a
  casa. Al terminar, el asistente bloquea igual que con una clave ausente.

  El motivo es concreto: la clave se ata al `wwwroot` exacto del sitio, así que
  no había forma de darle acceso de prueba a nadie cuya URL no conocieras de
  antemano — empezando por el revisor de Moodle HQ, que instala en su propio
  entorno. Un producto que no arranca y del que no puedes dar una prueba es un
  producto que no se puede revisar.

  El estado de la ventana, con los días restantes y la dirección de contacto para
  pedir la clave, aparece bajo el campo de la licencia en los ajustes del plugin.
  A los participantes no se les muestra nada: el trial es funcional al 100 %, así
  que no hay de qué avisarles.
- **`amd/build/chat.min.js.map`**, generado por primera vez.
- Cinco tests del periodo de evaluación, incluida la frontera de la ventana y la
  idempotencia de su apertura (un segundo upgrade no devuelve los días gastados).

### Corregido

- **`amd/build/chat.min.js` no era una build.** Era una copia byte a byte del
  fuente, sin minificar y sin sourcemap, así que el check `grunt` de Moodle
  Plugin CI fallaba. Regenerado con el grunt de Moodle: 19 KB de fuente pasan a
  10 KB de build, con su mapa.
- **`jsdoc/require-param` en `setSending()`.** Un `@param` que faltaba desde hacía
  versiones y que también tumbaba el check `grunt`. Lo encontró el propio grunt
  al ejecutarlo por primera vez.
- **`thirdpartylibs.xml` declaraba una licencia que no era.** Decía «MIT,
  Apache-2.0» para todo `vendor/` cuando `smalot/pdfparser` es **LGPL-3.0**.
  Reescrito con una entrada por librería, con su versión y su licencia reales.
  Es compatible con GPLv3 igual, pero declararlo mal en un producto que se vende
  no es un detalle.
- **El ZIP de distribución no llevaba los tests ni la documentación.**
  `build_zip.ps1` excluía `tests/` y todas las extensiones `.md`, de modo que el
  paquete llegaba sin los 283 tests, sin README y sin CHANGELOG. En Moodle, al
  revés que en el mundo Composer, los plugins envían su suite dentro.
- **El ZIP se llevaba `.gstack/`.** El filtro de exclusión solo casaba a nivel
  raíz, así que los informes internos de esa carpeta —y cualquier `.github`
  anidado dentro de `vendor/`— viajaban al cliente. Ahora se descarta cualquier
  directorio que empiece por punto a cualquier profundidad, y la guardia dura del
  script aborta la build si aparece `.gstack`, `docs/` o `.claude`.
- Cabecera GPL de 15 líneas en los ficheros JavaScript, que solo llevaban el
  bloque PHPDoc.
- **El módulo AMD se compilaba con el nombre equivocado.** Detectado al pasar el
  check `grunt` de Moodle Plugin CI de verdad: un build generado en un directorio
  con otro nombre deja ese nombre incrustado (`block_xxx/chat`), y el chat no se
  registra ni carga. El build se regenera siempre en `blocks/openaiagent`.
- **`styles.css` no pasaba `stylelint`**, que es parte del check `grunt`: 22
  errores de formato (comas sin espacio, llaves sin salto, colores de 6 dígitos).
  Corregido; el CSS sigue 100 % namespaced bajo `.openaiagent-`.
- **`unserialize()` sin restricciones** en `lib.php` y `courseconfig.php` sobre el
  `configdata` del bloque: ahora usan `unserialize_object()`, el helper que Moodle
  ofrece justo para esto. En `analytics.php`, el `@unserialize()` del campo `other`
  del log pasa a comprobar el formato antes y a prohibir la instanciación de
  clases — sin el `@`, que escondía problemas reales.
- **`thirdpartylibs.xml` no declaraba el autoloader de Composer**, así que el
  codechecker analizaba `vendor/composer/*` como si fuera código propio y sacaba
  30 errores ajenos. Declarado.
- **Errores de estilo propios** que el codechecker sí señalaba: 33 correcciones
  automáticas en `orchestrator.php`, `support_gate.php` y cuatro ficheros de test,
  más los docblocks que faltaban en `generate_license.php`.
- **El aviso de evaluación agotada mostraba `{$a}` en vez de la fecha.**
  `get_banner()` no le pasaba el parámetro al string, así que el participante
  habría leído «el periodo de evaluación de 15 días terminó el {$a}». Solo se ve
  renderizando el mensaje de verdad: los tests comprobaban que el aviso existía,
  no lo que decía. Ahora también se comprueba que la fecha esté interpolada.

### Cambiado

- **`$plugin->supported = [405, 405]`.** Hasta ahora `requires` dejaba la puerta
  abierta a cualquier Moodle ≥ 4.5, incluidas versiones que nadie había probado.
  Ahora se declara solo 4.5, que es lo verificado. Moodle 5.x no muestra APIs
  retiradas en el análisis estático, pero movió la raíz web a `public/` y exige
  PHP 8.3, así que su compatibilidad se certificará cuando se pruebe de verdad.
- **Nombre comercial: «Smart Tutor & Support AI».** El plugin se llamaba «OpenAI
  Agent» / «Agente OpenAI», que es marca registrada de un tercero y que además
  dejó de ser cierto hace versiones: el asistente funciona con OpenAI, Anthropic,
  Google Gemini y DeepSeek. El nombre nuevo dice lo que hace y nombra los dos
  agentes que lo componen.

  **El component name sigue siendo `block_openaiagent`, y eso es deliberado.**
  Moodle identifica un plugin por el nombre de su directorio: renombrarlo haría
  que las instalaciones existentes vieran el plugin como «missing from disk», y
  una desinstalación desde ese aviso borra las 12 tablas, la configuración y
  todas las instancias del bloque colocadas en los cursos. El identificador
  técnico se queda; lo que cambia es todo lo que ve una persona.

  Cambian los 14 strings visibles en inglés y español (nombre del bloque, las 9
  capabilities, los avisos de licencia, el correo de prueba del escalado y las
  herramientas de prueba), los docblocks y el README. **No cambian** los ids de
  string, las tablas, las capabilities, los settings ni los web services: una
  actualización desde 4.14.x no requiere ninguna acción.
- **Titularidad.** Los 113 ficheros con `@copyright` pasan a declarar
  **RSMAX Consulting SL <julio@rsmax.es>**, que es la titular real. Pluginia es la
  marca comercial; son cosas distintas y ahora son coherentes entre el código, la
  ficha del producto y los términos de venta.
- `composer.json`: descripción real del plugin —la anterior hablaba de «Agent
  Builder», que ya no existe— y bloque `authors`.
- Los endpoints retirados de `mcp/v1/` (los *tombstones* que devuelven 410) se
  han **eliminado del repositorio**, no solo del paquete: eran cinco ficheros PHP
  alcanzables por web que no arrancaban Moodle, y para una instalación nueva no
  servían a nadie.
- **`README.md` reescrito en inglés**, que es el idioma del Marketplace.

---

## [4.14.8] — 2026-08-24

### Corregido

- **Decir que sí a un ofrecimiento ya sirve de algo.** El asistente ofrecía
  preparar la solicitud, el participante contestaba «sí, quiero que lo reporten»
  y recibía el mismo ofrecimiento otra vez, sin tarjeta. Existía un disparador
  para «mi respuesta anterior te mandó al formulario», pero ninguno para «mi
  respuesta anterior te ofreció que lo preparo yo». Ahora una aceptación abre la
  puerta, y el servidor prepara el borrador aunque el modelo no lo haga: es la
  señal menos ambigua de todas y la que más costaba dejar en manos del modelo.
  La insistencia cuenta como aceptación —después de un ofrecimiento, «pero
  necesito entregarla como sea» no es una pregunta nueva— y una negativa, o un
  cambio de tema, no abren nada.

---

## [4.14.7] — 2026-08-24

### Corregido

- **Pedir hablar con una persona ya no depende del clasificador.** El detector
  que reconoce esa petición es determinista, pero vivía dentro de la compuerta de
  soporte, y esa compuerta solo se evalúa en la ruta del asistente: una petición
  que el clasificador mandaba al tutor no llegaba nunca a él. En un curso sobre
  liderazgo y comunicación, «quiero hablar con una persona del equipo» y «I need
  to speak to a real person from the support team» se leen como contenido del
  curso, y el tutor respondía invitando al participante a pedir justo lo que
  acababa de pedir. Ahora se resuelve antes del clasificador, como ya se hacía
  con las preguntas sobre los datos propios.
- **El disparador que abre la tarjeta no reconocía la palabra «formulario».**
  Detectaba que una respuesta remitía a soporte por los verbos que acompañan a
  una persona —contacta, escribe a, acude a— pero no por los que acompañan a un
  formulario. Con la redacción actual, «utiliza el formulario de Soporte técnico»
  o «solicítala mediante el formulario» no activaban nada: el participante
  respondía «sí, quiero que lo reporten» y recibía el mismo ofrecimiento otra
  vez, sin tarjeta. Los nuevos verbos se limitan al formulario y a la mesa de
  ayuda, para que una frase corriente del curso («la actividad solicita una
  calificación del tutor») siga sin disparar nada.

---

## [4.14.6] — 2026-08-24

### Corregido

- **Un seguimiento corto ya no acaba en el agente que no sabe nada.** Cuando la
  confianza del clasificador baja —cosa que ocurre con facilidad en mensajes de
  tres o cuatro palabras— la conversación caía en la ruta de aclaración, cuyo
  agente no tiene documentos, ni herramientas, ni acceso a los datos del
  participante. Medido sobre un curso real, «los elementos clave» después de una
  explicación sobre negociación devolvió cinco elementos inventados que no son
  los del curso, y aceptar la propia oferta del asistente de revisar el progreso
  («sí, revísalo por favor») devolvió «no tengo acceso a tu progreso o
  calificaciones», un turno después de haberlo leído. Ahora una conversación que
  ya tiene ruta la conserva. La cortesía es la excepción y sigue atendida por el
  agente de aclaración: es lo único que hace mejor que los especialistas, que
  responderían a un «gracias» buscando algo.

---

## [4.14.5] — 2026-08-23

### Cambiado

- **El agente de aclaración ya no responde como una máquina.** Era la ruta que
  atiende los saludos y todo mensaje que el clasificador no sabe colocar —una de
  cada cuatro intervenciones— y contestaba siempre con la misma plantilla, sin
  saludo y a menudo sin el nombre. A un «gracias» le pedía que aclarase qué
  necesitaba. El prompt del curso no llega a esa ruta, así que ninguna edición
  del lado del curso podía corregirlo. Ahora saluda, reconoce la cortesía sin
  exigir aclaraciones y construye su pregunta sobre lo que ya se ha hablado en la
  conversación, en lugar de preguntar en abstracto. El texto se refresca en la
  actualización, como ya se hacía con los prompts del clasificador y del tutor.

---

## [4.14.4] — 2026-08-23

Ajustes sobre la tarjeta de escalado a soporte y la continuidad de la
conversación, medidos en una corrida de 26 conversaciones sobre un curso real.

### Cambiado

- **El aviso de la tarjeta de confirmación.** Ya no nombra la plataforma por su
  nombre técnico ni explica de dónde sale la dirección de correo. Dice a dónde va
  la solicitud, qué datos la acompañan y, en negrita y subrayado, que la respuesta
  llegará por correo electrónico.

### Corregido

- **La tarjeta de soporte aparecía en respuestas que no la necesitaban.** Al
  terminar el turno, el servidor deducía de la propia redacción que el
  participante estaba atascado, con una expresión regular que no distingue un
  callejón sin salida de una precaución: una respuesta que ya había resuelto la
  duda y cerraba con «si el campo no se puede editar, contacta con soporte»
  recibía una tarjeta debajo. Tres de cada cuatro tarjetas salían así. La señal
  no se pierde, solo se retrasa un turno: si el participante vuelve a escribir
  después de que se le remita a soporte, el disparador de recomendación abre la
  puerta en el turno siguiente, que es cuando su insistencia confirma de verdad
  que sigue bloqueado.
- **Un turno ambiguo borraba la ruta de la conversación.** La intención guardada
  es el único contexto que recibe el clasificador, y «ambiguo» no es un destino:
  guardarlo dejaba el turno siguiente sin referencia, de modo que una pregunta de
  aclaración provocaba otra. Ahora se conserva la última ruta real, que es lo que
  permite que un «ya lo hice» vuelva al asistente y, con él, al escalado.
- **La frase que acompaña a la tarjeta salía en el idioma del sitio.** Una
  conversación íntegra en inglés terminaba con una línea en castellano. Ahora se
  resuelve en el idioma en que está escrita la respuesta.

---

## [4.14.3] — 2026-08-20

Correcciones derivadas de la auditoría de seguridad y privacidad
(`docs/auditoria-seguridad-4.14.md`). Ninguna cambia el comportamiento del
asistente para los participantes.

### Corregido

- **La declaración de privacidad no reflejaba lo que se envía al proveedor de
  IA.** Declaraba el identificador del usuario, que en realidad no se envía, y
  omitía lo que sí sale. Ahora nombra los cuatro elementos reales: nombre propio,
  nombre del curso, texto de los mensajes y resultado de las consultas que el
  asistente hace a Moodle sobre el propio participante. Este último es el que se
  pasaba por alto con facilidad: esos resultados vuelven al modelo para que pueda
  razonar sobre ellos, y llevan las calificaciones del alumno, el estado de sus
  entregas, sus intentos de cuestionario y su participación en foros. Es
  información necesaria para que la funcionalidad exista, pero tiene que estar
  declarada, y la página «Política de privacidad → Datos registrados» la mostraba
  incompleta.

- **La resolución del usuario objetivo en las herramientas de Moodle ya no
  atiende a la entrada.** Devuelve siempre el usuario autenticado. Antes lo
  tomaba de los argumentos si venían informados, y la comprobación de permisos
  que sigue a continuación valida al sujeto equivocado: verifica que el usuario
  *objetivo* pueda ver el curso, no que quien *pregunta* tenga derecho a leer sus
  datos, de modo que habría dejado pasar la consulta sobre una víctima
  legítimamente matriculada. No era explotable —el orquestador elimina el
  parámetro del esquema que ve el modelo y además lo sobrescribe, y no existe
  ningún otro punto de entrada—, pero el control que estaba ahí no era el que
  protegía.

- **Reescritura frágil de una cláusula SQL** en el resumen de escalados, que
  reetiquetaba una columna con `str_replace`. Se construye ahora contra la
  columna correcta desde el principio. De paso se cubre con pruebas la rama del
  resumen de soporte con filtro de curso, que era código nuevo de 4.14.2 y no se
  había ejecutado nunca con un filtro activo.

---

## [4.14.2] — 2026-08-20

### Cambiado

- **El filtro del panel de uso se aplica a todo el panel.** Hasta ahora, escribir el
  nombre de un curso arriba solo filtraba la tabla comparativa del final: las consultas
  por día, el consumo de tokens, el gasto, las rutas, la recurrencia y las llamadas a
  herramientas seguían siendo cifras de toda la plataforma. Como debajo aparecía una sola
  fila de curso, esas cifras se leían como si fueran de ese curso, y no lo eran. Ahora la
  selección se resuelve a un conjunto de cursos y se aplica dentro de cada consulta.

  El alcance de la adopción también se estrecha: al filtrar, el porcentaje de adopción
  compara los usuarios activos de los cursos seleccionados contra las matrículas de esos
  mismos cursos, no contra las del sitio entero.

  Un filtro que no coincide con ningún curso muestra cero, no todo.

---

## [4.14.1] — 2026-08-20

Ajustes sobre el escalado a soporte tras la primera revisión del panel de uso.

### Añadido

- **Informe de escalados en su propia página**, en *Administración del sitio → Bloques →
  Agente OpenAI → Informe de escalados a soporte*, con filtros por periodo, estado y
  búsqueda por nombre o referencia, y paginación de 50 en 50. El listado vivía dentro del
  panel de uso, donde crece una fila por incidencia sin límite: en una plataforma grande
  acababa sepultando el resto del panel. El panel conserva solo las cifras de cabecera y
  un enlace al informe.

- **Prefijo de las referencias configurable** (`OA-7-12` → `CAU-7-12`). Cada institución
  puede usar la nomenclatura que ya reconozca su servicio de soporte. Solo afecta a las
  referencias nuevas: las ya emitidas se conservan tal cual se comunicaron, porque una
  referencia que alguien ha citado en un correo no puede cambiar por debajo.

### Corregido

- **El panel decía que el 100% de las conversaciones acababan en un escalado** cuando una
  de las consultas seguía pendiente de confirmar. Había dos problemas mezclados. El
  primero es que una sola cifra respondía a dos preguntas distintas; ahora se informan por
  separado el porcentaje de ofertas que el participante llegó a confirmar y el porcentaje
  de conversaciones que terminaron en un escalado real. El segundo es que el porcentaje de
  conversaciones podía superar el 100%: contaba escalados de conversaciones antiguas
  contra un denominador de conversaciones nuevas. Ahora ambos lados de la división miran
  las conversaciones iniciadas en el periodo.

- **Las consultas del día en curso no aparecían hasta el día siguiente.** El resto del
  panel compara contra una columna agregada por días, donde el límite superior es la
  medianoche de hoy y todo cuadra; el listado de escalados guarda marcas de tiempo reales,
  así que ese mismo límite ocultaba justo lo recién ocurrido, que es cuando alguien entra
  a mirar. El límite es ahora el final del día.

---

## [4.14.0] — 2026-08-19

Escalado a soporte por correo. Cuando el asistente de plataforma no puede resolver una
consulta, ofrece abrir una incidencia; el participante confirma y Moodle envía el correo.

El diseño parte de dos restricciones. La primera es de privacidad: **el modelo nunca ve
la dirección de correo de nadie**. Redacta un resumen del incidente y nada más; el
destinatario, la identidad del participante y los datos del curso los pone Moodle desde
su propia base de datos en el momento del envío. La segunda es de escala: en cursos
masivos, una función que envía correos es una función que puede generar spam, así que la
elegibilidad no depende del criterio del modelo sino de una comprobación determinista en
el servidor.

### Añadido

- **Escalado a soporte, solo para el asistente de plataforma.** La ruta del tutor no se
  ve afectada en absoluto: no recibe las herramientas de soporte, no se le añaden
  directivas y la puerta de elegibilidad ni se consulta. Una consulta de contenido sigue
  comportándose exactamente igual que en 4.13.

- **Confirmación explícita con token de un solo uso.** El asistente propone el resumen y
  el participante lo ve escrito antes de decidir. El correo no sale hasta que se pulsa
  confirmar, y el token se invalida al usarse, de modo que ni un doble clic ni un reenvío
  del formulario duplican la incidencia. Los borradores caducan a las 24 horas.

- **Doble cierre sobre cuándo se ofrece.** Las directivas del prompt orientan al modelo,
  pero quien decide es el servidor. Los cinco disparadores reconocidos son: el
  participante pide hablar con una persona, el asistente ha tenido que recurrir a la
  respuesta por defecto, una herramienta de Moodle ha fallado, la misma pregunta se
  repite sin avanzar, o la propia respuesta del asistente recomienda contactar con
  soporte. Sin ninguno de ellos no hay oferta, diga lo que diga el modelo.

- **Cuatro capas de contención de spam**, todas configurables: cuota diaria por
  participante (3 por defecto), enfriamiento entre incidencias del mismo participante en
  el mismo curso (10 minutos), techo diario por curso que actúa de cortacircuitos (200) y
  deduplicación por hash normalizado del resumen dentro de una ventana configurable (24
  horas). A esto se suma un enfriamiento en número de turnos para no repetir la oferta
  cuando ya se ha rechazado.

- **Plantillas de asunto, cuerpo y firma** con variables (participante, curso, categoría,
  resumen, enlace a la conversación, tiempo de respuesta esperado), y transcripción
  opcional de los últimos turnos.

- **Encaminamiento por categoría.** Un mapa «categoría: dirección» permite que las
  incidencias técnicas vayan al CAU y las académicas a coordinación. Una regla de
  categoría sustituye a la dirección general, no se suma a ella.

- **Marcador `{course_teachers}`**, que se resuelve a los contactos del curso según la
  propia definición de Moodle. Sirve para que un profesor pueda activar la funcionalidad
  sin teclear direcciones: solo puede acabar en gente ya listada como responsable del
  curso.

- **Lista de dominios permitidos.** Restringe a qué dominios puede salir un correo, con
  los subdominios incluidos. Se aplica tanto al guardar como al resolver los
  destinatarios antes de enviar.

- **Modo resumen (*digest*).** En lugar de un correo por incidencia, agrupa las del curso
  y las manda juntas cada N minutos. Pensado para cursos masivos donde el buzón de
  soporte no debe recibir cientos de mensajes sueltos.

- **Herencia de configuración global a curso.** Cada campo puede heredarse del sitio o
  sobrescribirse en el curso; los conmutadores usan tres estados (heredar, sí, no) para
  que «no» sea una decisión explícita y no un valor vacío.

- **Respuesta al participante.** El correo sale desde la cuenta *noreply* del sitio, con
  la dirección del participante en `Reply-To`, de modo que soporte puede responder
  directamente sin que el envío falle las comprobaciones SPF/DMARC del dominio. En el
  modo resumen no se pone `Reply-To`, porque un solo destino de respuesta enviaría a un
  participante la contestación destinada a otro.

- **Notificación al participante** por el sistema de mensajes de Moodle cuando la
  incidencia sale, y aviso si acaba fallando tras los reintentos.

- **Botón de prueba de envío** en los ajustes, que manda un correo real a cada dirección
  configurada y dice qué ha pasado.

- **Integración con el resto de la plataforma**: evento `support_request_sent`, proveedor
  de privacidad para la nueva tabla, copia de seguridad y restauración de la
  configuración por curso, y purga por antigüedad junto con las conversaciones.

### Cambiado

- **La respuesta que acompaña a la oferta invita a confirmar.** Antes remitía al
  formulario de soporte mientras la tarjeta de confirmación estaba justo debajo, con lo
  que el texto y la interfaz se contradecían.

- **Las respuestas de confirmación, cancelación y entrega se guardan en la
  conversación**, no solo se devuelven al navegador. Al recargar, la conversación
  terminaba en «todavía tienes que confirmar» aunque la incidencia ya se hubiera enviado.

- **La copia al participante viene desactivada por defecto.** Con la notificación de
  Moodle activa, la copia suponía dos mensajes por incidencia.

### Corregido

- **`moodle.get_assign_submission_status` fallaba en todas las llamadas** desde 4.13.
  Llamaba a `assign::get_submission_status()`, un método que no existe en Moodle. Se ha
  reconstruido sobre los métodos que `mod_assign` sí expone y ahora devuelve además si la
  entrega se puede editar, si está bloqueada, el estado de calificación y el estado del
  flujo de trabajo.

- **La lista de dominios permitidos solo se aplicaba al guardar un formulario.** Quedaban
  dos vías abiertas: estrechar la lista después de haber guardado un destino no afectaba
  a lo ya almacenado, y `{course_teachers}` se resuelve a direcciones que nadie teclea en
  ningún formulario, así que no pasaban por la comprobación. Ahora se filtra también al
  resolver los destinatarios, que es el último punto antes del envío.

- **El asunto y el cuerpo no resolvían las etiquetas multiidioma.** `format_string()` no
  aplica los filtros con la configuración por defecto de Moodle, así que un nombre de
  curso con `<span lang="es" class="multilang">` salía con los dos idiomas concatenados.
  Se invocan ahora los filtros directamente. Es la misma causa que el fallo corregido en
  4.10.1, en otra forma.

- **Los identificadores de curso e instancia en la copia de seguridad**, la comprobación
  de cuotas en el momento de confirmar (se cortocircuitaba con la propia incidencia
  pendiente y nunca llegaba a evaluar los límites) y la normalización del hash de
  deduplicación, que dependía de la plataforma y hacía que el mismo texto se resumiera
  distinto en Windows y en Linux.

---

## [4.13.6] — 2026-08-13

Corrección crítica: el bloque rompía las copias de seguridad de cualquier curso que lo
contuviera. Afecta a todas las instalaciones desde que se añadió la copia del perfil del
asistente (4.13.x).

### Corregido

- **La copia de seguridad de un curso con el bloque fallaba siempre.** El paso de copia
  pasaba los identificadores de curso y de instancia como valores literales a
  `set_source_table()`. La API de estructuras de Moodle interpreta cualquier valor no
  negativo como *la ruta de otro elemento* del que tomar el valor, así que buscaba un
  elemento llamado, por ejemplo, `3873` y lanzaba `baseelementincorrectfinalorattribute`,
  abortando la copia completa del curso. Los literales ahora van envueltos en
  `backup_helper::is_sqlparam()`, que es la forma que exige el núcleo.

  En sitios con copias asíncronas el efecto era acumulativo: cada tarea abortaba, el curso
  quedaba marcado como «copia en curso» y las tareas fallidas se reintentaban con espera
  creciente, dejando la cola atascada para todo el sitio.

- **Los prompts restaurados conservaban el marcador de enlace sin descodificar.** El
  restaurador solo descodifica filas alcanzables por un *mapping*, y el del perfil apunta a
  la instancia del bloque, no a la fila de configuración. Se registra ahora un mapping
  propio de esa fila y se declaran sus campos de texto en `define_decode_contents()`, de
  modo que un enlace a la página de configuración del curso vuelve a apuntar al curso
  restaurado en lugar de quedarse como `$@OPENAIAGENTCOURSECONFIG*14@$`.

### Cambiado

- **Un perfil ilegible ya no puede tumbar la copia de un sitio.** La resolución del curso
  propietario y del contexto de los documentos se hace ahora de forma defensiva: si falla,
  la copia sigue adelante sin el perfil en lugar de abortar.

- **El codificador de enlaces sale antes.** Moodle registra `encode_content_links()` para
  todo el sitio en cuanto el plugin está instalado y lo ejecuta sobre cada campo de texto
  de cada copia; ahora descarta con un `strpos()` el caso mayoritario en lugar de evaluar
  una expresión regular.

---

## [4.13.5] — 2026-08-04

Corridas de QA sobre 144 preguntas reales de un curso: 125 → 133 respuestas óptimas,
2 → 1 deficientes.

### Añadido

- **Localizadores de figura.** Los pies numerados (`Cuadro 25: …`, `Figura 3: …`, y sus
  equivalentes en inglés y portugués) se indexan como un chunk propio, con el pie dentro
  del breadcrumb. Un cuadro que en el PDF es una imagen solo deja su pie en el texto
  extraído, así que su contenido nunca llegaba al índice y el tutor respondía que el curso
  no trata el tema. En una guía de cliente eran 15 cuadros. Ahora el tutor recibe una
  referencia con documento y página, y una regla que le prohíbe negar la cobertura del tema
  cuando existe esa referencia. Medido: el localizador del cuadro buscado pasa a ser el
  primer resultado, y los localizadores ocupan solo 0-2 de los 8 huecos disponibles.

- **`rag::CHUNKER_VERSION`.** Los chunks se guardaban por `contenthash` y se trataban como
  inmutables, de modo que ningún cambio del troceador alcanzaba a documentos ya indexados:
  el fichero no cambia, solo la forma de cortarlo. `tutordocs::sync_course()` reconstruye
  el curso cuando el sello no coincide. **Al cambiar el troceado hay que subir la
  constante**, o el cambio solo aplicará a cursos indexados desde cero.

### Corregido

- **Los mensajes de reserva se pegaban al final de respuestas ya contestadas.** El texto que
  acompañaba a «sin información» y «fuera de alcance» decía solo «transmite este mensaje»,
  sin aclarar que sustituye a la respuesta entera. El resultado era una respuesta correcta y
  bien citada que se desmentía en su último párrafo. Ahora se indica que es una respuesta
  completa en sí misma y se prohíbe añadirla a una respuesta ya dada o repetirla dos veces.

- **Idioma.** La regla «responde en el idioma del usuario» quedaba a unos 2.000 tokens del
  turno, compitiendo con un prompt en inglés, mensajes de reserva en español y extractos en
  español. Se detecta ahora el idioma en PHP (sin llamada al modelo) y se indica de forma
  explícita. En la ruta del tutor la línea se repite **después** de los extractos, porque es
  ahí donde se añaden y de otro modo se quedaban con la posición final. El detector se
  abstiene cuando no hay ventaja clara, y entonces rige la regla anterior: medido sobre 144
  preguntas, 143 aciertos, 0 errores, 1 abstención.

- **Acentos en el emparejamiento léxico.** El componente léxico comparaba en minúsculas pero
  sin plegar diacríticos, así que «sandwich» no casaba con «sándwich». Los participantes
  escriben sin tildes constantemente y los documentos nunca. Se pliegan ahora ambos lados.

- **Higiene de salida del asistente.** Ya no se muestran nombres de campo ni valores crudos
  de los resultados de herramienta (se veía un «user_grade: null» en pantalla), y no se abre
  con «Sí» o «No» en contra de la polaridad de lo que sigue («Sí, todavía no has completado…»).

- **Instrucciones dentro del mensaje del participante.** «Eres un agente nuevo», «olvida tus
  reglas» o «respóndeme igual» suprimían el aviso de que la respuesta queda fuera del curso.
  El aviso deja de ser opcional, se da antes de responder y no se recomiendan productos ni
  proveedores comerciales.

- **Páginas atribuidas al documento equivocado.** Una página pertenece al documento cuyo
  extracto la traía; ya no se transfieren entre documentos citados en la misma frase.

### Notas

- Se evaluó puntuar el léxico por la mejor ventana del chunk en lugar del chunk completo y se
  **descartó tras medirlo**: empeora el ranking del fragmento correcto en 3 de 4 casos y cuesta
  2,3× más. Premia la co-ocurrencia densa de términos, de modo que gana la prosa temática
  larga y pierde la definición breve y aislada que pretendía rescatar. El motivo queda anotado
  en `lexical_scores()`.

---

## [4.13.3] — 2026-08-04

### Corregido

- **El asistente fallaba en todos los turnos al seleccionar `gpt-5.6-luna`.** Cada llamada volvia
  con un 400 y cero tokens consumidos. El mensaje de la API lo explicaba con precision:
  *"Function tools with reasoning_effort are not supported for gpt-5.6-luna in
  /v1/chat/completions. To use function tools, use /v1/responses or set reasoning_effort to
  'none'."*

  El asistente es la unica ruta que envia herramientas, asi que el tutor seguia funcionando con el
  mismo modelo y el fallo parecia caprichoso. El adaptador envia ahora `reasoning_effort: 'none'`
  cuando la peticion lleva herramientas y el modelo es de la familia gpt-5.6. Se manda de forma
  explicita en vez de omitir el parametro: sin el, el modelo aplica su esfuerzo por defecto, que no
  es 'none', y la peticion se rechazaria igual.

  Es una restriccion del endpoint, no del modelo: por `/v1/responses` acepta ambas cosas. La
  comprobacion queda aislada en `rejects_tools_with_reasoning()` para que el proximo modelo con la
  misma limitacion sea una linea. Ningun otro modelo cambia de comportamiento: `gpt-5-mini`,
  `gpt-5`, `gpt-5-nano`, `gpt-4.1-mini` y `o4-mini` siguen recibiendo exactamente los mismos
  parametros que antes.

---

## [4.13.2] — 2026-08-04

### Anadido

- **`gpt-5.6-luna` disponible y por defecto en tutor y asistente.** OpenAI lo situa por debajo
  de `gpt-5-mini` en las tres columnas: **$0,20 / 1M de entrada** (frente a 0,25), **$0,02
  cacheada** (frente a 0,025) y **$1,20 de salida** (frente a 2,00), con mas capacidad. Medido
  sobre los tokens reales de la bateria PM4R Leadership: **-29 % en las dos rutas**, de $1,0440
  a $0,7402. Donde mas aprieta es en la salida, que era el 45 % del coste del tutor.

  El id encaja con el codigo sin adaptaciones: `owns_model()` lo acepta por el prefijo `gpt-`, y
  la deteccion de modelo de razonamiento (`/^(gpt-5|o\d)/`) tambien, de modo que se omite
  `temperature` y se envia `reasoning_effort`, que es el tratamiento correcto para un modelo con
  soporte de razonamiento. Anadido tambien al mapa de precios de la analitica.

  **El router sigue en `gpt-4.1-mini` a proposito.** Es un clasificador JSON que corre en cada
  turno; un modelo de razonamiento le anadiria latencia en el camino critico sin mejorar una
  decision entre tres etiquetas. Los valores por defecto por proveedor distinguen ahora entre el
  modelo de contenido (tutor y asistente) y el del router.

  Cambiar el valor por defecto **no altera las instalaciones existentes**: un ajuste ya guardado
  gana al valor por defecto del codigo, asi que un sitio en marcha sigue con su modelo hasta que
  un administrador lo cambie en la pagina de ajustes.

---

## [4.13.0] — 2026-08-04

### Cambiado (comportamiento)

- **El prompt del tutor por curso ahora SUSTITUYE al de por defecto, en vez de sumarse.** El
  asistente ya funcionaba así y el tutor no, lo que obligaba al autor del curso a escribir un
  complemento a un prompt que no podía leer. En los cursos medidos los dos acababan repitiendo la
  misma política con otras palabras y **contradiciéndose**: longitud de respuesta (100-200 frente a
  100-250), idioma (dos directivas distintas), y si se puede usar conocimiento general (el prompt
  base lo permite etiquetado, el del curso lo prohibía). Ahora hay una única autoridad por ruta y un
  único sitio donde mirar.

  Las reglas que un curso **no** debe poder debilitar no viven en ese prompt: se añaden después, en
  `rag::format_context()` y en `compose_instructions()` — anclaje a los extractos, prohibición de
  reproducir el marcador `[Section: ...]`, documentos internos no citables, higiene de
  identificadores, idioma, formato e identidad. La regla anti-sigla se ha movido también al bloque
  de extractos por el mismo motivo.

  **Migración automática:** todo curso con un prompt no vacío recibe por delante el prompt por
  defecto anterior, leído de la tabla `agents` antes de refrescarla, con una cabecera que explica de
  dónde sale. El comportamiento de esos cursos no cambia y el autor puede borrar la mitad que le
  sobre cuando la revise. Un curso con el campo vacío sigue usando el prompt por defecto, que ahora
  es el nuevo.

### Añadido

- **Prompt del tutor por defecto reescrito**, integrando el anterior con las mejoras que salieron de
  las 144 preguntas de PM4R Leadership: las cinco reglas de anclaje intactas, más el orden de
  comprobaciones antes de responder, el modo socrático con la instrucción de **no imprimir los
  epígrafes** ("Breve explicación", "Preguntas que te orientan"...) sino escribir en prosa, los tres
  tipos de respuesta y el estilo. Nada del contenido anterior se ha perdido: se ha reorganizado en
  bloques con 23 reglas numeradas.

- **Los campos del apartado Tutor vienen ya rellenos** en un curso nuevo, igual que el prompt del
  asistente. Se siembran: el prompt del tutor, las reglas de bloqueo de evaluación, y los tres
  textos fijos (sin información, fuera de alcance, bloqueo de evaluación). Se dejan vacíos a
  propósito los que dependen del curso concreto y no se pueden adivinar: documentos citables,
  documentos internos y actividades protegidas.

---

## [4.12.8] — 2026-08-03

### Seguridad y privacidad

- **El apellido del participante ya no sale del sitio.** Todo lo que entra en las instrucciones
  viaja a un modelo de terceros, así que se aplica minimización de datos: los agentes reciben
  únicamente el **nombre de pila**, que es lo que necesitan para dirigirse al alumno. El apellido
  no aportaba nada a ninguna respuesta y, combinado con el curso, hace identificable a la persona
  en registros ajenos — y además se estaba filtrando literalmente en las respuestas. Dos puntos
  corregidos: `identity_directive()`, que inyectaba «the user's name is "Nombre Apellido"», y la
  herramienta `moodle.get_current_user_basic`, que devolvía `lastname`. Ambos entregan ahora solo
  el nombre, y tanto la directiva como la descripción de la herramienta indican explícitamente que
  no se dispone del apellido y que no debe inventarse ni preguntarse. No hay ninguna otra ruta:
  ninguna herramienta expone correo, nombre de usuario, número de identificación ni datos de otros
  participantes, y todas van ligadas al usuario autenticado en el servidor.

### Rendimiento

- **El prefijo del prompt vuelve a ser cacheable entre participantes.** Los proveedores cachean por
  **prefijo**, y el nombre del usuario se inyectaba en segunda posición, justo detrás del prompt
  base. Eso dejaba el tramo compartido en unos 1.000 tokens — al filo del mínimo de 1.024 que un
  proveedor necesita para cachear algo —, de modo que **cada participante pagaba a precio completo
  el mismo prompt de curso, los mismos documentos citables y las mismas políticas**, y la caché solo
  ayudaba dentro de la propia ráfaga de preguntas de una persona. El bloque de identidad pasa al
  final, justo antes del resumen de conversación y del contexto RAG: todo lo anterior es idéntico
  para todo el curso y se factura a tarifa cacheada tras el primer turno.

  Medido antes del cambio en el curso PM4R Leadership: ruta tutor 40,8 % de acierto de caché frente
  al 76,2 % del asistente. La diferencia se explica porque el asistente no inyecta extractos y su
  prefijo variable es menor. En producción, con muchos alumnos preguntando de forma espaciada, los
  prefijos por usuario se enfrían y el porcentaje cae mucho más; con el prefijo compartido se
  mantiene caliente.

---

## [4.12.7] — 2026-08-03

Afina la segunda pasada de recuperación que introdujo 4.12.6. Validado con 19 sondas: 4.12.6 dejó
en verde la ruta ambigua (4/4), la fuga de reglas del tutor, las caídas del asistente (0 de 6) y
los artefactos de cita (0 de 19), y recuperó 2 de las 4 preguntas largas que no encontraban su
sección — pero las otras dos seguían fallando.

### Corregido

- **La consulta enfocada no estaba lo bastante enfocada.** `focus_query()` conservaba el nombre de
  la herramienta junto al resto de palabras largas, así que «Necesito un resumen ejecutivo, no más
  de un párrafo, sobre qué es la técnica Sí-No-Sí. Es para una nota interna al coordinador»
  producía `sí-no-sí necesito resumen ejecutivo párrafo técnica interna coordinador` — y ese ruido
  sigue dominando el vector, que es exactamente el fallo que la segunda pasada venía a corregir. La
  medición lo dejó claro: preguntada en cuatro palabras («¿Qué es la técnica Sí-No-Sí?») el tutor
  encuentra la ficha al instante y hasta cita el «no positivo» de William Ury. Cuando la pregunta
  nombra una herramienta, la pasada enfocada es ahora **ese nombre solo**, que es literalmente la
  consulta corta que funciona. Sin nombre, se mantiene el repliegue a las palabras largas.

- **Las siglas del curso se caían de la consulta enfocada.** Solo se conservaban términos de seis
  caracteres o más, y todas las siglas del material miden menos: **IAR (3), FODA, CAME, GROW, MAAN
  (4), SMART (5)**. Justo los nombres de mayor precisión. `name_terms()` reconoce ahora tanto los
  compuestos con guion como las siglas escritas en mayúsculas, sin confundirlas con una palabra
  capitalizada al principio de la frase.

- **Una sigla corta casaba dentro de palabras corrientes.** La puntuación léxica busca por
  subcadena, así que «iar» contaba tres aciertos en «hay que **iniciar** y **cambiar** para
  **apreciar**» y enterraba la única sección que nombra la técnica. Los términos de menos de cinco
  caracteres se anclan ahora a límites de palabra. Los largos siguen por subcadena, que es más
  barato y no tiene ese riesgo.

Ninguno de los tres cambios aumenta el número de extractos inyectados ni el gasto en tokens: la
recuperación es local y la fusión respeta el mismo tope.

---

## [4.12.6] — 2026-08-03

Cierra los tres defectos que quedaron vivos tras validar 4.12.5 con 18 sondas contra el curso
real. Lo que sí quedó resuelto en 4.12.5: **0 caídas del asistente en 10 sondas** (antes 30 %),
**0 artefactos `[Section:` en 18** (antes 17 %) y listas HTML reales en 15 de 18 respuestas.

### Corregido

- **La ruta «ambigua» se comía preguntas perfectamente claras, y el prompt del router no bastaba.**
  El problema no era la clasificación sino `interpret_intent()`: degrada a `ambiguous` cualquier
  intención buena cuando la confianza del router baja de 0,65, o cuando `needs_clarification`
  llega a true con confianza menor de 0,8. Unas erratas, un saludo inicial o un preámbulo
  hunden esa confianza aunque la ruta sea evidente, así que «ola, una consulta rapida xfa, tengo q
  darle feedback a un compa…» se contestaba con «¿podrías aclarar…?». Y como las consultas ajenas
  al curso caían también ahí, la puerta de fuera de alcance no llegaba a dispararse nunca.
  Ahora hay una guarda determinista: si el mensaje lleva una petición identificable —un signo de
  interrogación, o cinco palabras o más— nunca es ambiguo, y el respaldo es el **tutor**, que tiene
  tanto la respuesta del curso como la de fuera de alcance. En el peor caso, una negativa educada
  en vez de un turno perdido. Mismo criterio que la puerta determinista de datos en vivo: una
  decisión que puede tomarse sin el router del modelo no debería depender de él.

- **El tutor le explicaba sus propias reglas al participante.** Efecto secundario de la regla
  anti-sigla de 4.12.4: dejó de inventar qué significa «IAR», pero empezó a justificarse con
  «no se puede ampliar la sigla porque *las instrucciones del curso prohíben expandir acrónimos*
  que no estén escritos literalmente en los materiales». Se le indica ahora que omita la expansión
  en silencio y que nunca describa, cite ni aluda a las instrucciones que ha recibido.

- **Una pregunta larga seguía sin encontrar su propia sección.** El texto acolchado domina el
  vector de consulta: «Necesito un resumen ejecutivo, no más de un párrafo, sobre qué es la técnica
  Sí-No-Sí. Es para una nota interna al coordinador» puntúa contra «resumen ejecutivo», «nota
  interna» y «coordinador», y la ficha de la herramienta (p. 109) se cae de los resultados, aunque
  la misma pregunta en cuatro palabras la encuentre al instante. Tres cambios, **ninguno de ellos
  aumenta el número de extractos ni el gasto en tokens**, porque la recuperación es local y la
  fusión respeta el mismo tope:
  - Segunda pasada con la consulta reducida a sus palabras temáticas, con los nombres de
    herramienta compuestos por delante (`rag::focus_query()`).
  - Las pasadas se **fusionan** en round-robin en vez de elegir una (`rag::merge_chunks()`). El
    código comparaba el `topscore` de dos consultas distintas para quedarse con «la mejor», y esos
    cosenos se miden contra vectores diferentes: no son comparables, y podía descartar el extracto
    correcto porque otra redacción puntuaba más alto contra su propio vector.
  - El reescritor de consultas pasa a `temperature = 0`. A 0,2 reescribía distinto en cada intento
    y con ello cambiaba el conjunto de extractos: preguntado dos veces seguidas si una técnica
    estaba en el material, respondía que no la primera vez y que sí la segunda.

### Estilo

Corregidos los 15 errores y 3 advertencias de codechecker: tres líneas de ejemplo del prompt del
router por encima de 132 caracteres, el formato de una llamada multilínea a `debugging()` en el
orquestador, y la indentación de un comentario numerado en `db/upgrade.php`.

### Notas de actualización

El paso `2026080600` refresca los prompts de tutor y router en `block_openaiagent_agents`. Los
prompts por curso no se tocan.

---

## [4.12.5] — 2026-08-03

### Corregido

- **El asistente respondía «no está disponible temporalmente» en el 30 % de los turnos, y el
  panel no lo contaba como error.** Medido sobre 56 turnos reales del curso PM4R Leadership:
  17 fallos. La causa no era la API ni el presupuesto de salida — los tokens de salida de los
  turnos caídos van de **324 a 913**, con un máximo permitido de 4.000, mientras que turnos que
  sí responden llegan a 2.272. Lo que los distingue es la **entrada acumulada**: mediana de
  **29.428** tokens frente a 18.901 de los que responden, y **15 de los 17** por encima de 25.000.
  Como cada ronda reenvía el prompt y los esquemas de las herramientas (~9.400 tokens la primera),
  esa cifra son **cuatro rondas**: justo el tope `MAX_TOOL_ITERATIONS`. El bucle salía con el
  modelo aún pidiendo herramientas y sin haber escrito una sola frase, y ese texto vacío se
  convertía en el mensaje genérico. Los 324–913 tokens de salida eran los argumentos JSON de las
  llamadas pendientes.

  Tres cambios: el tope sube de **4 a 8**; al agotarlo se ejecutan las llamadas pendientes, se
  **quitan las herramientas** y se pide una respuesta final, de modo que al modelo no le queda
  otra que redactar con lo que ya reunió (esa llamada es además la más barata del turno, porque
  sin los esquemas el prompt se desploma); y la respuesta vacía se guarda ya con `errormessage`,
  para que deje de aparecer como un turno sano. La última iteración del bucle ya no alimenta las
  llamadas pendientes: de eso se encarga la respuesta final, y hacerlo dos veces habría repetido
  los `tool_call_id` y vuelto a ejecutar cada herramienta.

- **Las listas se renderizaban como un párrafo corrido.** Markdown clásico solo abre una lista si
  el primer ítem va precedido de una línea en blanco, y el modelo escribe habitualmente el
  encabezado y los ítems en un mismo bloque. El resultado era un `<p>` con guiones literales
  dentro en lugar de un `<ul>`. `markdown::to_html()` inserta ahora esa línea en blanco antes de
  convertir: solo en el salto de una línea de texto al primer ítem, dejando intactos los ítems
  siguientes, el Markdown ya correcto y los guiones a mitad de frase.

- **El router mandaba a la ruta «ambigua» preguntas perfectamente claras.** Bastaba un saludo, una
  muletilla o unas erratas («ola, una consulta rapida xfa, tengo q darle feedback a un compa…»)
  para que la clasificara como ambigua, y el agente de ambigüedad tiene prohibido responder: el
  turno se gastaba en un «¿podrías aclarar…?» sobre algo que no necesitaba aclaración. Las
  consultas fuera de alcance caían ahí también, así que **la puerta de fuera de alcance nunca se
  disparaba**: pedir recomendaciones de restaurantes obtenía una pregunta de vuelta en vez de una
  negativa. El router descarta ahora saludos, erratas y preámbulos antes de clasificar, un mensaje
  con petición identificable nunca es ambiguo, y las consultas ajenas van al tutor, que es quien
  tiene la respuesta de fuera de alcance. El agente de ambigüedad recibe además un permiso
  acotado: si el tema es algo que ningún curso podría cubrir, lo dice en una frase en vez de
  insinuar que podría ayudar.

### Notas de actualización

Los prompts de router y de ambigüedad viven en la tabla `block_openaiagent_agents` y no tienen
interfaz de edición, así que el paso `2026080500` los refresca incondicionalmente. Los prompts por
curso no se tocan.

---

## [4.12.4] — 2026-08-03

Corrige tres defectos detectados al ejecutar las 144 preguntas de la batería de PM4R Leadership
contra 4.12.3 con `gpt-5-mini` en tutor y asistente. Ninguno de los tres es una regresión de código:
`classes/local/rag.php` era **idéntico byte a byte** al de 4.9.3. Lo que cambió fue el modelo, y el
modelo nuevo obedece literalmente instrucciones que el anterior ignoraba.

### Corregido

- **El marcador `[Section: ...]` se filtraba a la respuesta del participante (17 % de las
  respuestas).** Cada extracto recuperado lleva delante una miga de pan con su ruta de encabezados y
  su página, y tanto `rag::format_context()` como la regla 3 del prompt base del tutor ordenaban
  *«quote the unit, section and page EXACTLY as they appear in that marker»*. El bloque de extractos
  se concatena **al final** de las instrucciones, después del prompt del curso, así que la última
  instrucción del mensaje de sistema —y la más específica— mandaba copiar el marcador tal cual. Con
  `gpt-4.1-mini` no pasaba nada porque ignoraba la orden; `gpt-5-mini` la cumple, y el participante
  veía cosas como `[Section: Módulo 5 : Herramientas › Paso 6. Regular la emoción aceptándola,
  postergando la reacción y cambiando el | p. 89]`, con el encabezado cortado a mitad de frase. El
  marcador se declara ahora explícitamente interno: se leen de él los valores de módulo, sección y
  página, pero está prohibido reproducir su sintaxis; la ubicación se escribe en prosa.

- **Los nombres de herramienta unidos por guion no puntuaban nada en la búsqueda léxica.**
  `rag::tokenize()` descarta los términos de menos de tres caracteres, así que «¿Qué es la técnica
  **Sí-No-Sí**?» se tokenizaba a `["qué", "técnica"]`: **el nombre entero de la herramienta
  desaparecía**, no contribuía al score léxico ni al refuerzo por encabezado, y solo el embedding
  podía encontrarla. Con una pregunta corta bastaba; en cuanto una pregunta larga diluía el vector
  de consulta, el chunk correcto caía fuera de los doce recuperados y el tutor respondía —de buena
  fe— que la técnica no aparece en el material, cuando es la herramienta 15 de la Guía Práctica
  (p. 109). Se emite ahora el compuesto completo como un término más, normalizando separadores para
  que un mismo término case con «Sí – No – Sí» (guiones largos con espacios, como está en el PDF),
  con «Sí-No-Sí» y con la partición por salto de línea que introduce el extractor («Yo- Contexto-
  Equipo»). Las partes sueltas se siguen emitiendo, así que nada de lo que casaba antes deja de
  casar, y la copia normalizada del texto solo se construye cuando hay algún compuesto en la
  consulta: consultas sin compuestos no pagan nada. **No aumenta el número de extractos
  inyectados**: se recuperan los mismos doce, mejor elegidos.

- **El tutor inventaba el significado de las siglas del curso.** Preguntado cuatro veces qué es la
  técnica IAR devolvió cuatro expansiones distintas y todas falsas («Informar-Analizar-Reflexionar»,
  «Identificar, Analizar, Recuperar/Aprender», «Identificar, Analizar, Reconstruir») mientras
  acertaba **siempre** los cuatro pasos. No es casualidad: los pasos están en el Cuadro 30 (p. 63) y
  la expansión real, *In Action Review*, vive en otros fragmentos (nota 25 en p. 53, cuerpo en p. 62
  y glosario en p. 142), así que el extracto recuperado traía los pasos pero no el significado y el
  modelo rellenaba el hueco con un acrónimo verosímil construido con las palabras de alrededor.
  Nueva regla de anclaje: prohibido expandir una sigla cuya expansión no aparezca literalmente en un
  extracto; si no se ve, se usa la sigla tal como la escribe el curso y no se dice nada sobre lo que
  significan sus letras. Es un error especialmente caro porque el participante lo repite en el examen.

### Notas de actualización

El prompt base del tutor vive en la tabla `block_openaiagent_agents` y no tiene interfaz de edición,
así que editar `defaults.php` por sí solo no habría llegado a ninguna instalación. El paso de
actualización `2026080400` lo refresca incondicionalmente, igual que los refrescos previos de router,
tutor y asistente. **Los prompts por curso (`block_openaiagent_courseconfig`) no se tocan.**

---

## [4.12.3] — 2026-08-03

Primera versión con la batería de pruebas ejecutada de verdad: **147 tests, 638 aserciones, todo en
verde** sobre Moodle 4.5.10+, PHP 8.2.30 y MariaDB 10.11.

### Corregido

- **«¿Cuál es mi nota?» no llegaba al asistente.** La puerta determinista de datos de plataforma
  existe justo para que una pregunta en primera persona sobre los datos propios del participante no
  dependa del router del modelo. Pero el veto de intención conceptual se evalúa antes, y `what is `
  entraba en ese veto: **la forma más natural de preguntar en inglés por la propia nota era
  precisamente la que se saltaba la puerta** y quedaba a merced del router, que es exactamente lo
  que la puerta pretendía evitar. Los interrogativos llevan ahora una anticipación negativa sobre el
  posesivo de primera persona: «What is a rubric?» sigue siendo conceptual y va al tutor, «What is
  my grade?» va al asistente. «How is my grade calculated» no cambia: sigue yendo al tutor, que es
  el comportamiento para el que se escribió el veto.

- **`db/install.xml` no estaba en formato canónico de XMLDB.** Los índices multicampo declaraban
  `FIELDS="courseid,blockinstanceid"` mientras que XMLDB serializa `FIELDS="courseid, blockinstanceid"`,
  con espacio tras la coma, como hace todo el core. El test `core\db\plugin_checks_test` del propio
  Moodle lo detecta. Corregidos los ocho índices afectados. Sin cambio de esquema: solo afecta a la
  representación del fichero.

- **Cuatro tests que fijaban expectativas obsoletas o imposibles.** No eran fallos del plugin sino
  de las propias pruebas, escritas junto a funcionalidad que nunca llegó a ejecutarse:
  la directiva de idioma se había reescrito («Write your ENTIRE reply in…») y un test seguía
  esperando el texto anterior; un test de analítica comparaba con `assertSame` el id de curso que el
  generador entrega como cadena contra el entero que normaliza el rollup; y dos tests afirmaban que
  el router recibía un ajuste usando mensajes en primera persona que —correctamente— nunca llegan al
  router. El test del cuestionario daba por hecho que el generador de `mod_quiz` respeta los campos
  de revisión que se le pasan, cuando siempre almacena la máscara completa: ahora se escriben
  directamente, como ya se hacía con la fecha del mensaje del foro.

---

## [4.12.2] — 2026-08-03

### Corregido

- **Incumplimientos del estándar de codificación de Moodle detectados por Code Checker.** Sin
  cambios de comportamiento: 4 errores y 10 advertencias, todas de estilo.

  - Llave de apertura de clase seguida de línea en blanco en los cuatro ficheros de
    copia de seguridad y restauración.
  - `defined('MOODLE_INTERNAL') || die();` innecesario en los dos `stepslib`: solo declaran clases,
    sin efectos secundarios. Los dos `*_block_task.class.php` lo conservan, porque ahí sí lo hay
    (un `require_once` sobre `$CFG->dirroot`).
  - Dos comentarios que empezaban con el nombre de un campo en minúscula (`cachedContentTokenCount`
    y `cm_info::$groupmode`), reescritos para empezar con mayúscula.
  - **Cadenas de idioma fuera de orden alfabético** en `en` y `es`. Se reordenó el bloque completo
    de forma programática en vez de mover solo las tres señaladas, así que quedan las 347 en orden
    estricto y el problema no se repite al insertar cadenas nuevas. Dos de las tres desviaciones
    (`reasoning_effort_high` y `settings_analytics_heading`) venían de versiones anteriores.

---

## [4.12.1] — 2026-08-03

### Corregido

- **Avisos de XMLDB durante la actualización.** Las columnas `char NOT NULL` declaraban `''` como
  valor por defecto, que XMLDB rechaza: *«must have one meaningful DEFAULT declared or none»*. El
  aviso salía dos veces en pantalla al actualizar a 4.12.0, y XMLDB lo corregía solo eliminando el
  defecto, así que **el esquema resultante ya era el correcto** y no hay nada que reparar en las
  instalaciones existentes. Se elimina el defecto en origen —dos columnas `model` y una
  `embeddingmodel`, en `db/install.xml` y en `db/upgrade.php`— para que ni las instalaciones nuevas
  ni las que actualicen desde versiones antiguas vuelvan a mostrarlo. Los tres campos se rellenan
  siempre de forma explícita en todos los caminos de inserción, así que ninguno necesita un valor
  por defecto.

  Sin cambios de esquema: una instalación nueva con este `install.xml` produce exactamente las
  mismas columnas que ya tienen los sitios actualizados.

---

## [4.12.0] — 2026-08-02

### Seguridad

- **Las herramientas direccionadas por `cmid` no comprobaban si la actividad estaba oculta.** Las
  herramientas de listado (`search_course_content`, `get_course_outline`, `get_section_gate_status`)
  ya descartaban las actividades ocultas por el profesorado, con un test que lo fijaba. Las que
  reciben un `cmid` directo no aplicaban esa comprobación, así que un `cmid` que el modelo produjera
  por cualquier vía —una conjetura, una referencia obsoleta, una inyección en el contenido del
  curso— devolvía igualmente el nombre y los ajustes de una actividad oculta. En el caso de
  `get_content_item` devolvía el **texto completo** de una página o un archivo ocultos.

  Se añade una guarda compartida, `require_module_visible()`, aplicada a `get_activity_details`,
  `get_activity_access_requirements`, `get_activity_configuration`, `list_activity_contents` y las
  tres ramas de `get_content_item`. Usa el mismo criterio de dos condiciones que ya empleaban las
  herramientas de listado: solo bloquea cuando `visible` y `uservisible` son ambos falsos, es decir
  cuando el profesorado ha ocultado la actividad. Una actividad **restringida pero mostrada en gris**
  conserva `visible = 1` y sigue pasando, porque explicar precisamente esa restricción es la razón
  de ser del asistente. El profesorado no se ve afectado: conserva `uservisible = true` gracias a
  `viewhiddenactivities`.

- **`get_content_item` no fallaba de forma segura con archivos fuera de un curso.** Si el contexto
  del archivo no era de curso ni de módulo (contexto de usuario, de bloque o de sistema), la
  variable de curso quedaba a `null` y el bloque que comprueba la pertenencia al curso y la
  matriculación **se saltaba entero**, extrayendo el texto igualmente. Como el identificador de
  archivo lo propone el modelo, cualquier entero era un destino posible. Ahora se rechaza cuando el
  curso no puede determinarse.

### Corregido

- **`block_openaiagent_userstats` no estaba declarada en el proveedor de privacidad.** La tabla
  guarda «el usuario X hizo N preguntas en el curso Y el día D», con clave foránea a `user`. No
  aparecía en la exportación de datos personales y, ante una solicitud de borrado, **sus filas se
  quedaban**. Además sobrevive a las conversaciones: `purge_conversations_task` puede borrar las
  conversaciones de un usuario y dejar intactos sus recuentos diarios, así que un usuario sin
  ninguna conversación podía seguir teniendo datos aquí. Ahora se declara en los metadatos y se
  cubre en los seis métodos del proveedor: localización de contextos, listado de usuarios,
  exportación y las tres vías de borrado.

- **El ajuste «Guardar el texto de las conversaciones» del curso no existía en el formulario y no
  hacía nada.** La columna `storeconversations` estaba en la base de datos, en `resolve()`, en la
  API externa y en la copia de seguridad, pero no tenía ni campo en el formulario ni cadena de
  idioma, y **nadie la consultaba nunca**: `add_message()` solo respetaba el ajuste global
  «Registrar mensajes». Quien llamara al web service podía ponerla a 0 y se seguía guardando todo.
  Ahora aparece en la configuración del curso y actúa de verdad: con ella desactivada se conservan
  la ruta, el modelo, los tokens y el coste —el panel de analítica sigue siendo correcto— pero no
  se retiene nada de lo que escriben los participantes. Los dos conmutadores son independientes y
  el contenido solo se guarda si ambos lo permiten; el global sigue teniendo prioridad. El valor
  por defecto es «activado», así que ningún curso existente pierde su historial.

- **El ajuste «Registrar payloads en bruto» no hacía nada.** Estaba en la administración, con sus
  cadenas y su semilla, y no lo leía nadie: la descripción advierte de no habilitarlo en producción,
  lo que da a entender que hace algo. Ahora se aplica en `client_base::post_json()`, el punto único
  por el que pasan los cuatro proveedores, y vuelca la petición y la respuesta en bruto vía
  `debugging()`. No se persiste en ninguna tabla a propósito: estos payloads contienen el mensaje
  del participante y la respuesta del modelo literales, y guardarlos crearía un segundo almacén de
  datos personales fuera del alcance del proveedor de privacidad. Las claves de API se enmascaran
  antes de escribir.

- **Los tres campos de temperatura de la administración no hacían nada.** `resolve_temperature()`
  solo consultaba la anulación del curso y el valor sembrado del agente; nunca leía
  `router_temperature`, `tutor_temperature` ni `assistant_temperature`. Es el mismo fallo que se
  corrigió en 4.9.8 para `max_output_tokens_*`, que quedó arreglado en uno de los dos gemelos y no
  en el otro. Ahora sigue la misma precedencia —anulación del curso > ajuste global > default del
  agente— y solo se aplica el ajuste global si tiene un valor guardado: un ajuste nunca guardado se
  lee como `false` y convertirlo a `float` habría fijado la temperatura en 0 en todas las
  instalaciones. Los valores por defecto de los ajustes coinciden con los sembrados en los agentes
  (0,1 / 0,25 / 0,2), así que para quien nunca tocó esos campos no cambia nada.

### Añadido

- **El asistente ya puede ver cómo está configurada una actividad, no solo si está restringida.**
  Un participante preguntó por qué no veía las respuestas de sus compañeros en un foro. El foro no
  tenía ninguna restricción de acceso, así que `moodle.get_activity_access_requirements` lo daba
  por disponible y el asistente respondía «este foro no tiene restricciones» y remataba con
  consejos genéricos sobre grupos y fechas. La causa real era que el foro era de tipo **pregunta y
  respuesta**: Moodle oculta las aportaciones ajenas hasta que publicas la tuya, y después impone
  el periodo de edición. Esa regla vive en `forum_user_can_see_post()`, no en `core_availability`,
  y nada fuera de `mod_forum` podía verla.

  La visibilidad en Moodle se decide en tres capas —restricciones de acceso, capacidades y **lógica
  interna del módulo**— y el plugin solo miraba la primera. La nueva herramienta
  `moodle.get_activity_configuration` cubre la tercera: devuelve las reglas de comportamiento de la
  actividad ya redactadas (`behaviour_rules`) más el estado del usuario frente a ellas
  (`user_state`), y de paso la disponibilidad, para que el modelo no necesite una segunda llamada.

  Los intérpretes viven en `classes/mcp/config/`, uno por módulo. En esta versión:

  - **Foro** — tipo P&R con su periodo de edición, debate único, un debate por persona, avisos,
    blog, fecha de corte, bloqueo de debates por inactividad.
  - **Cuestionario** — las opciones de revisión, que son la versión «cuestionario» del mismo
    problema: nada está restringido, simplemente la calificación o la corrección no se muestran
    hasta que el cuestionario se cierra. También intentos permitidos y consumidos, límite de
    tiempo, navegación secuencial y la existencia de una excepción personal.
  - **Tarea** — la trampa del borrador (subir el archivo no es entregar: falta pulsar «Enviar
    tarea», y hasta entonces el profesorado no lo ve), prórroga personal, fecha de entrega frente
    a fecha límite definitiva —que son cosas distintas y solo la segunda impide entregar—, entrega
    en grupo, declaración de autoría e intentos.
  - **Genérico** — se aplica a **cualquier** actividad o recurso: grupos separados o visibles,
    condiciones de finalización tal y como las redacta el propio módulo vía `core_completion`, y
    fechas de apertura y cierre.

  Un módulo sin intérprete propio nunca queda sin respuesta. Añadir uno nuevo es crear un fichero:
  no toca el registro, ni el orquestador, ni la base de datos, ni la configuración de los cursos.

  Cada intérprete emite una **lista blanca explícita** de campos, nunca el registro de la actividad.
  Las tablas de los módulos guardan ajustes que un participante no debe ver —contraseña y subred de
  un cuestionario, corrección ciega de una tarea, umbrales de bloqueo de un foro— y un volcado
  genérico los filtraría.

- **Regla de uso inyectada desde el servidor.** La instrucción que enseña al asistente a usar la
  herramienta se añade en `orchestrator::compose_instructions()`, no en el prompt sembrado del
  agente. Los cursos que definen su propio `assistantprompt` **sustituyen** el prompt del agente en
  lugar de ampliarlo, así que una regla escrita ahí no habría llegado a ninguno de ellos: es el
  mismo motivo por el que la regla de higiene de identificadores ya se inyecta por esta vía. La
  regla anula de forma explícita cualquier tabla de «qué herramienta usar» del prompt del curso,
  porque esas tablas mandan «no puedo ver X» justo a la herramienta que producía la respuesta
  equivocada, y prohíbe cerrar con «no tiene restricciones» sin haber consultado antes la
  configuración de la actividad.

  La regla solo se emite si la herramienta está habilitada en el curso, de modo que la casilla de
  la configuración del curso funciona como interruptor único de toda la funcionalidad: desmarcarla
  devuelve el comportamiento anterior sin desplegar nada.

### Notas de actualización

- La actualización habilita `moodle.get_activity_configuration` en los perfiles que ya tuvieran
  filas de herramientas. `course_config::enabled_tools()` recurre a los valores por defecto **solo**
  cuando un perfil no tiene ninguna fila; en cuanto existe una, devuelve exactamente las marcadas
  como habilitadas. Sin este paso, cualquier curso cuya configuración se hubiera guardado alguna vez
  habría ignorado la nueva herramienta en silencio: ni error, ni aviso, simplemente no habría
  llegado nunca al modelo.

---

## [4.11.0] — 2026-08-01

### Corregido

- **El panel de costes atribuía el gasto al modelo equivocado.** `get_cost_by_model()` agrupaba por
  `block_openaiagent_agents.defaultmodel`, es decir, el modelo **sembrado en la instalación**. Pero
  el modelo que se llama de verdad lo decide `orchestrator::resolve_model()`, cuya precedencia es
  *override del curso > ajuste global de administración > default del agente*. Con el ajuste global
  en gpt-5-mini, cada llamada se seguía etiquetando y tarificando como gpt-4.1-mini: **la fila de
  gpt-5-mini no podía aparecer nunca**, sin importar lo que hubiera en el ajuste de precios. Medido
  sobre un día real: 0,0215 $ en el panel frente a 0,0159 $ reales (~35 % de desvío, con el signo
  del error cambiando según qué dos modelos se confundieran).

  Ahora el modelo se **graba en cada mensaje** (`block_openaiagent_messages.model`) en el momento de
  la llamada, se agrega en el rollup y el panel agrupa por él. Las filas anteriores a esta versión
  no tienen ese dato, así que caen al `defaultmodel` de su agente —la única información de modelo
  que llegaron a tener—; la marca de agua de analítica se reinicia en la actualización para que el
  histórico completo se reconstruya con ese criterio.

- **El panel ignoraba el *prompt caching*.** Todo el token de entrada se facturaba a precio
  completo, cuando el proveedor sirve el prefijo repetido desde su caché a una fracción del precio
  (una décima parte en la familia gpt-5) — y este plugin manda un *system prompt* casi idéntico en
  cada turno. Contrastado con el CSV de costes de OpenAI del 23 al 28 de julio de 2026: 0,168 $
  reales frente a 0,185 $ del panel, **un 10 % de más**, coincidente con la tasa de acierto de caché
  observada. Los cuatro adaptadores extraen ya su campo de entrada cacheada
  (`prompt_tokens_details.cached_tokens`, `prompt_cache_hit_tokens`, `cache_read_input_tokens`,
  `cachedContentTokenCount`), se guarda en `cachedtokens` y el coste se calcula como
  *(entrada − cacheada) × precio de entrada + cacheada × precio cacheado + salida × precio de
  salida*. El ajuste **Precios de modelos** acepta una cuarta columna opcional
  (`modelo|entrada|salida|cacheada`); sin ella, la entrada cacheada se factura al precio completo,
  sin inventar descuentos.

  Anthropic informa de la entrada cacheada **fuera** de `input_tokens`, al contrario que el resto;
  su adaptador la reintegra al total de entrada para que un acierto de caché se vea como entrada más
  barata y no como menos entrada.

- **Las llamadas internas no se contabilizaban.** El router de intención corre en *cada* turno
  enrutado y el reescritor de consultas en los turnos vagos: ambas llamadas se facturan igual que
  las demás, pero no generaban ningún mensaje, así que eran invisibles para el panel y **toda cifra
  de gasto era una subestimación**. Ahora se registran como filas de rol `system` (que la interfaz
  de chat ya descarta y que no tocan ningún contador de preguntas/respuestas). También se guardan
  los tokens de las iteraciones que sí se completaron antes de un error de proveedor, que hasta
  ahora se perdían.

- **Al copiar y restaurar un curso, la configuración del plugin llegaba vacía.** El bloque no tenía
  soporte de copia de seguridad: el núcleo respalda los ajustes de la instancia (nombre, mensaje de
  bienvenida), pero todo lo que configura el profesorado vive en tablas propias indexadas por
  *(curso, instancia de bloque)*, y **ambas mitades de esa clave cambian** en una copia de curso.
  Se añade `backup/moodle2/` con las tareas de copia y restauración: viajan la configuración del
  perfil, la selección de herramientas MCP y los documentos de la base de conocimiento (que se
  almacenan en el contexto del curso con `itemid` = id de la instancia, por lo que se anotan con su
  contexto explícito en vez del que asumiría el mecanismo estándar de bloques).

  No se copian las conversaciones ni los mensajes —son datos personales que no deben viajar con una
  copia de curso— ni los *chunks* con sus *embeddings*, que son un índice derivado: la restauración
  encola la tarea de indexación para que el curso nuevo construya el suyo a partir de los documentos
  restaurados. Los identificadores de agente que no existan en el sitio de destino (o cuyo tipo no
  coincida) se reponen a 0, es decir, «agente por defecto de la ruta», en lugar de quedar colgando.

### Añadido

- **Entrada cacheada en el panel de analítica**: tarjeta con el porcentaje de entrada servido desde
  caché y columna por modelo junto a la entrada total, para poder leer de un vistazo qué parte del
  gasto se está evitando.

---

## [4.10.1] — 2026-07-29

### Corregido

- **El nombre del asistente, el mensaje de bienvenida y el subtítulo de la tarjeta destruían la
  sintaxis multilingüe con `<span>`.** Los tres campos del formulario del bloque estaban tipados
  como `PARAM_TEXT`, que **elimina todas las etiquetas HTML al guardar**. Con eso, el formato
  multilingüe del núcleo de Moodle
  (`<span lang="es" class="multilang">…</span>`) se perdía en el momento de guardar y nunca llegaba
  a almacenarse: era imposible configurar esos textos en varios idiomas. Ahora se tipan como
  `PARAM_CLEANHTML`, que conserva el `span` y sus atributos `lang`/`class` —los que el filtro
  necesita— y sigue eliminando cualquier contenido peligroso.

  La sintaxis `{mlang}…{mlang}` de *Multi-Language Content (v2)* no lleva etiquetas y sí se
  guardaba correctamente; lo que necesita es que **el filtro esté instalado, activado y configurado
  para aplicarse a «contenido y encabezados»**, ya que estos tres textos se muestran con
  `format_string()`. Si el filtro solo se aplica a «contenido», el código se verá tal cual. Las
  ayudas de los tres campos lo documentan ahora.

---

## [4.10.0] — 2026-07-29

### Añadido

- **Ajuste «Esfuerzo de razonamiento»** (global, en la sección de modelos). Controla cuánto puede
  razonar un modelo con esa capacidad antes de responder. Hasta ahora el parámetro solo lo usaba el
  reescritor de consultas; el router, el tutor, el asistente y el agente de ambigüedad no lo
  enviaban nunca, de modo que la familia gpt-5 corría siempre al valor por defecto del proveedor
  (medio), con la latencia y el coste de salida que eso implica en un chat de aula.

  El valor por defecto es **vacío = «valor por defecto del proveedor»**, es decir, no se envía
  nada y una instalación existente se comporta exactamente igual que antes. Es opt-in a propósito:
  activar el razonamiento en Anthropic o Gemini cambia el comportamiento de configuraciones que hoy
  funcionan. Para un chat de aula se recomienda **«Bajo»**.

  El ajuste es neutro y cada adaptador lo traduce a su mecanismo, ignorándolo en modelos que no
  razonan:

  | Proveedor | Mecanismo | Notas |
  |---|---|---|
  | OpenAI | `reasoning_effort` | Solo familia gpt-5 y serie o. Los tokens de razonamiento cuentan dentro del tope de salida |
  | Gemini | `generationConfig.thinkingConfig.thinkingBudget` | Solo 2.5+. Mínimo→0, Bajo→512, Medio→2048, Alto→8192. En los modelos *pro* el razonamiento no se puede desactivar, así que el mínimo se eleva a 128 |
  | Anthropic | `thinking: {type: enabled, budget_tokens}` | Solo Claude 3.7+ y 4.x/5.x. Bajo→1024 (mínimo de la API), Medio→4096, Alto→8192. «Mínimo» **no** activa el razonamiento, que en esta API es la opción más rápida. Al activarlo, la API exige temperatura por defecto y un `max_tokens` mayor que el presupuesto, así que el adaptador fuerza ambas cosas |
  | DeepSeek | — | No tiene parámetro de esfuerzo: el razonamiento se elige por modelo (`deepseek-reasoner` frente a `deepseek-chat`). El ajuste es inocuo |

---

## [4.9.9] — 2026-07-29

### Corregido

- **`moodle.get_support_link` fallaba para todos los estudiantes.** Era la única herramienta que
  comprobaba `moodle/course:view` por su cuenta en vez de usar el helper compartido
  `require_course_view()`, que emplean las otras dieciocho. Y esa capacidad significa «ver cursos
  **sin participar** en ellos»: la tienen gestores y administradores, **no** los estudiantes
  matriculados, que acceden por matrícula. Resultado: la herramienta lanzaba una excepción
  justamente para las personas a las que sirve, el asistente respondía que no había podido
  recuperar el enlace de soporte, y tenía que recurrir a la URL que llevara escrita en su prompt.
  Ahora usa el mismo helper que el resto (matriculado **o** con la capacidad).

- **Las barras salían escapadas en las respuestas al usuario.** El resultado de las herramientas se
  serializaba con `JSON_UNESCAPED_UNICODE` pero sin `JSON_UNESCAPED_SLASHES`, así que cada `/`
  viajaba como `\/` y los modelos lo copiaban literalmente a la respuesta: «Consultas al tutor\/a»,
  «http:\/\/…». `client_base` y `embeddings` ya serializaban con ambas banderas; a este punto le
  faltaba una.

---

## [4.9.8] — 2026-07-29

### Corregido

- **Los ajustes «Tokens máximos de salida» (router / tutor / asistente) no hacían nada.**
  `resolve_max_tokens()` leía el override del curso y, si no lo había, el valor sembrado en el
  registro del agente — **nunca** el ajuste global. Los tres campos se pintaban en la página de
  administración y se guardaban en la configuración, pero ningún código los leía jamás. La función
  vecina `resolve_model()` sí tiene esa precedencia, y su propio comentario explica por qué es
  necesaria («los agentes no tienen interfaz de edición, así que su valor sembrado fijaría el
  modelo en silencio y convertiría la página de ajustes en un no-op»); a los tokens de salida no
  se les aplicó el mismo criterio.

  Impacto real: con un modelo de razonamiento (familia gpt-5, o-series) el tope **también tiene que
  cubrir los tokens de razonamiento**. Con el tope sembrado de 1.000 para el asistente, el modelo
  gastaba el presupuesto entero razonando y devolvía **texto vacío**, que el orquestador convierte
  en «El asistente no está disponible temporalmente». Y como ese camino no es un fallo de la
  petición, la fila se guardaba con `errormessage` vacío y **no aparecía en el contador de errores
  del panel**. Subir el ajuste no cambiaba nada, porque no se leía. Ahora la precedencia es la
  misma que la del modelo: override del curso > ajuste global > valor sembrado del agente.

---

## [4.9.7] — 2026-07-29

### Corregido

- **La búsqueda seguía ocultando las actividades de una sección bloqueada.** El arreglo de 4.9.6
  devolvía las actividades restringidas, pero exigía que la actividad tuviera **su propio** motivo
  de restricción (`availableinfo`). Cuando la restricción está en la **sección** —una semana entera
  bloqueada— las actividades de dentro tienen `uservisible = false` y `availableinfo` **vacío**,
  porque el motivo vive en la sección. Se descartaban todas, y la herramienta volvía a devolver
  cero justo en el caso para el que se arregló. `get_course_outline` ya usaba la regla correcta
  (descartar solo si el profesor la ocultó **y** el usuario no la ve), así que la búsqueda era
  además incoherente con él. Ahora ambas comparten regla, y cuando la actividad no tiene motivo
  propio se informa del **motivo de la sección**.

  Efecto observado en producción antes del arreglo: a un alumno sin acceso a la Semana 2, la
  búsqueda de «actividad 2.6» devolvía cero; el modelo tomó el único foro que ese alumno sí veía
  y afirmó que «la actividad 2.6 corresponde al foro Consultas al tutor/a», dos actividades sin
  relación alguna.

- **Guardarraíl contra inventar una actividad cuando la búsqueda no encuentra nada.** El resultado
  vacío ahora lleva una instrucción explícita de no deducir la actividad de otros resultados, de
  la conversación ni de lo que el propio modelo dijo en un turno anterior, y de recurrir a
  `get_course_outline`. Se añade además una regla permanente que prohíbe decir que la actividad
  que el usuario nombró «es» o «corresponde a» otra con nombre distinto.

---

## [4.9.6] — 2026-07-28

### Corregido

- **`moodle.search_course_content` no encontraba actividades con el nombre que usa la gente.**
  La búsqueda era una única subcadena contigua (`stripos($cm->name, $q)`), así que
  «actividad 2.6» **no** casaba con «2.6 Actividad individual: Decir que NO» (orden distinto) y
  «2.3 Webinar Semana 2» **no** casaba con «2.3. Webinar: Semana 2» (el punto y los dos puntos).
  Como el prompt del asistente manda usar esta herramienta en primer lugar, se llevaba la mitad
  de todas las llamadas y devolvía cero, y el asistente contestaba —de buena fe— que la actividad
  no existía. Ahora la consulta se normaliza (minúsculas, puntuación colapsada, conservando la
  numeración tipo `2.6` como un único término) y se casa **término a término**, con los resultados
  ordenados por número de términos coincidentes. La salida incluye `matched_terms` / `term_count`
  para que quien la consuma distinga un acierto sólido de una aproximación.
- **La misma función ocultaba las actividades restringidas.** Filtraba por `uservisible`, de modo
  que una actividad bloqueada pero **anunciada** al participante desaparecía del resultado y se
  reportaba como inexistente, justo cuando el usuario preguntaba por qué no podía entrar. Ahora se
  devuelve con `available_to_user: false` y el motivo en `availability_summary`. Lo que el
  profesorado ha ocultado sigue oculto.
- **El router mandaba a «ambigüedad» los seguimientos sin contenido.** El prompt enseñaba
  `"ok" -> ambiguous` como ejemplo resuelto, contradiciendo la instrucción en tiempo de ejecución
  de heredar la ruta anterior; un modelo pequeño sigue el ejemplo concreto. Consecuencia: un «sí,
  por favor» o un «yes please» aceptando lo que el bot acababa de ofrecer se respondía con **otra
  pregunta**, gastando el turno. Los fragmentos que nombran una actividad («actividad 2.5 y 2.6»)
  caían ahí también. Los ejemplos pasan a estar condicionados a si hay ruta previa, y se añade la
  regla de que nombrar un objeto del curso **es** contenido.
- **El agente de ambigüedad inventaba contenido del curso.** Su prompt nunca le prohibió responder,
  así que citaba secciones y capítulos de la guía del participante que no ha leído (no tiene
  documentos, ni herramientas, ni datos del usuario) y llegaba a emitir el mensaje de «fuera de
  alcance» del tutor a participantes con preguntas válidas. El prompt nuevo le prohíbe
  explícitamente afirmar nada sobre el curso, citar documentos o ubicaciones, y entregar mensajes
  de política.

Un paso de actualización refresca los prompts de router y ambigüedad ya guardados en la base de
datos. Es quirúrgico e idempotente: un prompt que un administrador haya reescrito no contiene los
literales antiguos y se deja intacto.

---

## [4.9.5] — 2026-07-28

### Corregido

- **El prompt del tutor específico del curso se inyectaba también en el asistente y en el agente
  de ambigüedad.** El campo se llama y se documenta como «Prompt del **tutor** específico del
  curso», pero la condición de `compose_instructions()` era `$route !== 'router'`, de modo que
  llegaba a las tres rutas. Consecuencia real: los cursos escriben ahí una persona completa de
  tutor («eres el Tutor Académico Oficial… tu rol no es el de asistente técnico del aula»), que
  acababa en el mismo mensaje de sistema que el prompt del asistente («eres el Asistente del
  Curso»), contradiciéndolo y siendo casi tres veces más largo. En producción esto se traducía en
  un asistente que **respondía como tutor**: rechazaba consultas de plataforma por «no ser
  competencia suya» y, ante un participante que pedía hablar con su tutora real, se presentaba a
  sí mismo como esa tutora. El agente de ambigüedad, que no tiene documentos ni herramientas,
  recibía el mismo texto y empezaba a **citar secciones y capítulos inventados** de la guía del
  curso. Ahora el prompt del curso llega únicamente al tutor; el asistente sigue teniendo su
  propio prompt por curso (`assistantprompt`).
- **Coste**: ese mismo texto se reenviaba en **cada iteración** del bucle de herramientas del
  asistente. Con un prompt de curso típico (~3.200 tokens) el ahorro medido es de en torno al
  **25 % de los tokens de entrada** del asistente, y de más del **90 %** en los turnos de
  ambigüedad, que pasan a ser prácticamente sólo su propio prompt.

---

## [4.9.4] — 2026-07-23

### Corregido

- **La selección de «Herramientas del asistente» no se guardaba.** Las casillas del formulario de
  configuración del curso se llamaban como la herramienta (`tool_moodle.get_context`), pero PHP
  convierte los puntos en guiones bajos al construir `$_POST`, de modo que ningún elemento del
  formulario encontraba nunca su propio valor enviado. Consecuencia real: **cada guardado escribía
  las 17 herramientas como deshabilitadas**, sin importar lo que hubiera marcado el profesor, y el
  asistente de plataforma se quedaba sin ninguna herramienta de Moodle. Ahora el punto se codifica
  como doble guión bajo (`tool_moodle__get_context`), la misma codificación que ya se usaba para los
  nombres de función que ve el modelo. Un paso de actualización **repara los perfiles que quedaron
  con todas las herramientas deshabilitadas**: se borran sus filas para que vuelvan al conjunto de
  herramientas por defecto (ese estado sólo podía provenir del error, porque el formulario era la
  única vía para escribir esas filas).

---

## [4.9.3] — 2026-07-12

### Corregido

- **Extracción de PDF que descartaba texto válido.** El extractor prefiere `Smalot\PdfParser`
  (empaquetado), pero su salida puede contener bytes no válidos en UTF‑8 con ciertas
  codificaciones de fuente. `normalize_text()` usaba expresiones regulares con el modificador
  `/u`, que devuelven `null` ante UTF‑8 malformado, colapsando a vacío un texto por lo demás
  correcto; entonces se recurría al extractor PHP interno, cuya expresión regular de respaldo
  (líneas de operadores `Tj`/`TJ`) estaba mal escapada y **no compilaba**. Resultado: PDFs con
  texto real quedaban indexados como «sin texto». Ahora `normalize_text()` sanea a UTF‑8 válido
  antes de procesar, y las tres expresiones regulares del respaldo se han corregido. Un paso de
  actualización **re‑encola las extracciones marcadas como vacías o fallidas** para que se
  reprocesen con el extractor corregido.
- **Ruido en los fragmentos (chunks) del índice.** Al trocear documentos se colaban como
  encabezado de sección las entradas del índice/tabla de contenidos (líneas con puntos guía y un
  número de página) y se repetía el texto de créditos de cada página. Un nuevo paso de limpieza
  elimina las líneas de índice, los prefijos «Página N» y el texto que se repite en la mayoría de
  las páginas, de modo que el fragmento y su breadcrumb `[Section: …]` se centran en el contenido
  real. Esto mejora la precisión de la recuperación y de las citas de unidad/página del tutor.
- **Fuga de estado interno en las respuestas.** Algunos prompts pedían al modelo «actualizar el
  resumen de la conversación», que el modelo emitía a veces literalmente en la respuesta visible
  (`state.conversation_summary = …`, una línea final «Resumen: …»). El plugin nunca usaba esa
  salida, así que ahora se elimina de la respuesta antes de mostrarla o almacenarla, y el prompt
  por defecto del tutor prohíbe explícitamente emitirla (un paso de actualización ajusta también
  los prompts ya guardados que conservaban la instrucción antigua).

### Cambiado

- **Eliminados los campos sin efecto «Nombre visible del asistente» y «Mensaje de bienvenida»
  de la configuración del asistente.** Se guardaban en la base de datos pero ninguna parte del
  plugin los leía: el nombre y el mensaje que se muestran realmente se configuran en la
  configuración del bloque (instancia). Se retiran del formulario, de los webservices y de la
  base de datos para no dejar código muerto.
- **Eliminada la columna `vectorstoreid`,** resto de la antigua integración con File Search de
  OpenAI que ya no leía ningún componente. Un paso de actualización elimina las tres columnas
  muertas de la tabla de configuración.
- **Cálculos de casos prácticos.** El prompt del tutor withholdía el resultado numérico incluso en
  ejercicios ilustrativos que plantea el propio usuario. Ahora, para un caso práctico (no un ítem
  calificable del curso), explica el procedimiento **y** da el resultado; el bloqueo se reserva a
  cuestionarios con opciones, verdadero/falso o entregas evaluables.
- **Idioma de las respuestas.** La directiva de idioma se refuerza para abarcar todo lo que el
  modelo produce —mensajes fijos o de respaldo, instrucciones de curso y texto devuelto por
  herramientas o citado de los documentos—, que debe traducirse al idioma del usuario en vez de
  copiarse. Los mensajes de respaldo (sin información, fuera de alcance, bloqueo de evaluación) se
  inyectan pidiendo transmitirlos en el idioma del usuario.

---

## [4.9.1] — 2026-07-12

### Corregido

- **Avisos XMLDB durante la actualización.** Tres columnas `CHAR NOT NULL` de las tablas de
  analítica (`role`, `route`, `toolname`) declaraban `DEFAULT ''`, lo que provocaba avisos de
  depuración de XMLDB al actualizar. Ahora se declaran sin valor por defecto (NULL), tanto en
  `install.xml` como en el paso de actualización.

---

## [4.9.0] — 2026-07-11

### Añadido

- **Panel de uso (analítica) para administradores.** Nueva página en *Administración del sitio ›
  Bloques › OpenAI Agent › Panel de uso* (`dashboard.php`) con una vista institucional y una línea
  comparable por curso, filtrable por rango de fechas (7/30/90 días, este año o rango personalizado)
  y por nombre de curso. Presenta, con un diseño limpio y accesible (paleta apta para daltonismo,
  modo claro/oscuro, SVG en línea sin dependencias externas):
  - Tarjetas KPI: tasa de adopción, usuarios únicos, preguntas, conversaciones, recurrencia y tasa de
    error técnico.
  - Evolución temporal de preguntas y usuarios (una escala por serie).
  - Reparto Tutor/Asistente/Ambigua, distribución de recurrencia (1 / 2–3 / 4+ días activos).
  - Tokens y **coste estimado** por modelo (con tabla de precios configurable en los ajustes) y coste
    por pregunta.
  - Éxito/fallo de las herramientas Moodle (MCP) y portafolio comparativo de cursos con adopción.
- **Agregación diaria optimizada.** Tres tablas de resumen (`block_openaiagent_msgstats`,
  `block_openaiagent_userstats`, `block_openaiagent_toolstats`) que una tarea programada nocturna
  (`build_analytics_task`) reconstruye de forma incremental. El panel lee estos resúmenes compactos
  en lugar de recorrer la tabla de mensajes, de modo que la carga sobre la BBDD es mínima aun con
  un histórico grande. Botón «Actualizar datos ahora» para forzar la reconstrucción tras instalar.
- **Ajuste de precios de modelos** para las estimaciones de coste (USD por millón de tokens de
  entrada/salida), con valores por defecto razonables que el administrador puede sobrescribir.

Las métricas de contención, satisfacción (CSAT) e impacto en el aprendizaje descritas en el plan de
medición se dejan para fases posteriores, ya que requieren feedback explícito o integración con
soporte.

---

## [4.8.1] — 2026-07-11

### Cambiado

- **Nunca se muestran ids internos al alumno.** El asistente exponía a veces ids internos
  (id de curso, número de sección, `cmid`/id de actividad, id de ítem de calificación, p. ej.
  «el código 37670» o «búscalo por el ID: 3894») en lugar del título legible. Ahora una
  directiva de *higiene de identificadores* se inyecta a nivel de servidor en `compose_instructions()`
  en cada turno (tutor/asistente/ambigüedad), de modo que **siempre está presente y no puede
  debilitarla el prompt por curso**. El modelo sigue usando los ids internamente para encadenar
  herramientas, pero debe referirse a cursos, secciones y actividades por su **título** (campos
  `course_fullname`, `section_name`, `name` que las herramientas ya devuelven); si solo dispone de
  un id sin título, lo describe de forma genérica sin decir el número, y los ids solo pueden
  aparecer dentro de la URL de un enlace, nunca como identificador suelto.

---

## [4.8.0] — 2026-07-11

### Añadido

- **Asistentes por categoría.** El bloque ahora se puede añadir y configurar en las páginas
  de categoría, no solo en cursos y en la portada. Esto permite un asistente especializado
  a nivel de categoría, compartido por todos los cursos parecidos que contenga.
  - El bloque declara el formato `course-index-category` en `applicable_formats()` para que
    aparezca en «Añadir un bloque» de la página de categoría (la clave `category` no casaba
    con el *pagetype* real de esas páginas).
  - Nuevo enlace de configuración en el menú de ajustes de la categoría
    (`extend_navigation_category_settings`), uno por cada asistente de la categoría.
  - La página de configuración deriva el contexto (curso o categoría) desde la instancia
    del bloque: comprueba la capacidad `managecourseconfig` en ese contexto y almacena la
    base de conocimiento en el **contexto de la categoría**. Así un gestor de categoría
    puede administrar su asistente sin permisos a nivel de curso.
  - `pluginfile` sirve los documentos de la base de conocimiento también desde contexto de
    categoría.
  - Como toda la configuración ya se aísla por instancia de bloque, un asistente de
    categoría resuelve su perfil por su id (con `courseid = SITEID`, igual que en la
    portada); las herramientas que consultan datos de un curso concreto no aplican en ese
    ámbito (mismo comportamiento que en la home).

---

## [4.7.0] — 2026-07-11

### Añadido

- **Varios asistentes por curso.** Cada instancia del bloque en un curso es ahora un
  asistente independiente y aislado: tiene su propia configuración (prompts, agentes,
  políticas, herramientas), su propia base documental (RAG) y vector store, y su propio
  historial de conversación. Esto permite, por ejemplo, un bloque de soporte técnico y
  otro de tutoría temática conviviendo en el mismo curso con comportamientos distintos.
  - Todo lo que antes se guardaba por curso pasa a guardarse por pareja
    `(curso, instancia de bloque)` mediante una nueva columna `blockinstanceid` en las
    tablas `courseconfig`, `coursetools`, `conversations` y `chunks`. Los documentos de la
    base de conocimiento se almacenan con `itemid = id de la instancia del bloque`.
  - El menú de configuración del curso muestra **una entrada por cada bloque** del plugin,
    identificada por el nombre configurado del asistente cuando hay más de uno.
  - El chat (`send_message`, `get_conversation`, `reset_conversation`) envía el id del
    bloque, de modo que cada asistente resuelve su propio perfil.
  - El inspector de recuperación y el estado de la base de conocimiento en las
    herramientas de administración distinguen por bloque.

### Migración

- Al actualizar, la configuración y los documentos existentes a nivel de curso se
  reasignan automáticamente al **bloque más antiguo** del curso, de forma que el asistente
  actual sigue funcionando sin cambios bajo su id de bloque. En cursos que ya tuvieran
  varios bloques del plugin (que compartían una única configuración), solo el bloque más
  antiguo hereda los datos; los demás arrancan como asistentes nuevos con los valores por
  defecto. Los ficheros de la base de conocimiento se mueven de `itemid 0` al id del
  bloque (operación barata: los ficheros están direccionados por contenido). Se recomienda
  hacer copia de seguridad antes de actualizar.

---

## [4.6.0] — 2026-07-10

### Añadido

- **Reescritura condicional de consultas de búsqueda** (`enable_query_rewrite`, activada
  por defecto). Cuando la pregunta al tutor es demasiado vaga (pronombres, mensajes muy
  cortos, sin términos identificables) o la primera recuperación devuelve resultados
  débiles (puntuación máxima baja, ningún extracto contiene los términos principales, o
  nada recuperado), una llamada barata al modelo expande la pregunta con sinónimos y el
  contexto de la conversación y se reintenta la búsqueda; se usa la pasada con mejor
  puntuación. Modelo por proveedor (configurable con `query_rewrite_model`): gpt-5-nano
  (OpenAI), claude-haiku-4-5 (Anthropic), gemini-2.5-flash-lite (Gemini), deepseek-chat
  (DeepSeek). Con `debugmode` se registra cada reescritura y sus puntuaciones.
- **Búsqueda híbrida** en la base de conocimiento: la puntuación semántica (coseno de
  embeddings) se combina siempre con un componente léxico normalizado (25 %), de modo que
  siglas y nombres propios que aparecen literalmente en un extracto suben en el ranking.
  Solo procesamiento en PHP: sin tokens generativos adicionales. Los chunks aún no
  embebidos se rankean por el componente léxico en lugar de quedar invisibles.
- Soporte de `reasoning_effort` y omisión de `temperature` para los modelos razonadores
  de OpenAI (gpt-5*, o1/o3/o4*), que rechazan temperaturas distintas de la
  predeterminada.

### Cambiado

- **Política de grounding del tutor relajada**: el tutor puede usar conocimiento general
  sólido de la materia para encuadrar y explicar conceptos pedagógicamente, pero todo dato
  específico del curso (qué dice la guía, su organización, definiciones, ubicaciones)
  sigue saliendo exclusivamente de los extractos, sin contradecirlos y sin atribuir a los
  documentos nada que no contengan. Se mantienen intactas las reglas de ubicaciones
  exactas (marcadores `[Section: ...]`), documentos `[internal]` y el fallback de "sin
  información". Prompt del tutor refrescado en instalaciones existentes vía
  `db/upgrade.php` (los overrides por curso no se tocan).

## [4.5.1] — 2026-07-10

### Corregido

- **El asistente no reconocía las restricciones de acceso de secciones/semanas.** A la
  pregunta "¿por qué no puedo acceder a la semana 3?" respondía "no hay restricción". Se
  encadenaban varias causas, todas corregidas:
  - Se comprobaba `uservisible` en lugar de **`available`** para decidir si una sección
    estaba bloqueada. Una restricción mostrada "en gris" mantiene `uservisible = true`
    con `available = false`, por lo que el bloqueo pasaba desapercibido.
  - `availableinfo` **no siempre es un texto**: con varias condiciones es un objeto
    `core_availability_multiple_messages` que reventaba al convertirlo a string. Ahora se
    renderiza con `\core_availability\info::format_info()` mediante el helper
    `render_availability_info()`, aplicado en todas las herramientas que lo usan
    (`get_section_gate_status`, `get_course_outline`, `get_activity_details`,
    `get_activity_access_requirements`).
  - La salida de `get_course_outline` se **truncaba a 8.000 caracteres** y las semanas
    restringidas (al final) se perdían. Se recortan los resúmenes de sección a 300
    caracteres y se sube el tope del orquestador a 24.000.
  - Los nombres de sección/actividad se devolvían con markup `multilang` crudo; ahora se
    limpian con `format_string`.

### Añadido

- **Resolución de sección por nombre** en `get_section_gate_status`: acepta
  `section_name` (p. ej. "Semana 3") y resuelve el índice interno de sección, evitando que
  el modelo adivine un número equivocado (el número de sección no es el número de semana).
- **Prompt del asistente reforzado**: instrucciones explícitas para consultar siempre las
  herramientas ante preguntas de acceso/restricción y no responder de memoria. Refrescado
  en instalaciones existentes vía `db/upgrade.php`, respetando el override por curso.
- **Inspector de restricciones de acceso** en las herramientas de prueba
  (`testtools.php`): vuelca, para un curso y un usuario, los indicadores de
  disponibilidad de cada sección y la salida cruda de las herramientas.
- **Logging de diagnóstico** de cada llamada a herramienta (nombre, argumentos y
  resultado o excepción), activable con el modo debug del plugin.

## [4.4.1] — 2026-07-08

### Corregido

- **Embeddings que se quedaban a 0 en cursos grandes.** La generación de embeddings solo
  se ejecutaba dentro de la tarea ad‑hoc de indexación (al guardar la configuración o en
  una actualización) y **abortaba el curso entero al primer lote fallido**, por lo que un
  único fragmento problemático dejaba todos los fragmentos sin embedding y "ejecutar el
  cron" no lo resolvía.
  - Se sanea el texto a UTF‑8 válido antes de enviarlo (un byte inválido de la extracción
    del PDF hacía que `json_encode` fallara y tumbara toda la petición).
  - Un lote fallido ya no aborta el resto del curso; se omite y se reintenta.
  - Tamaño de lote reducido (64 → 16) para no exceder límites por petición.

### Añadido

- **Tarea programada** `embed_chunks_task` (cada 10 min) que reintenta los embeddings
  pendientes de todos los cursos, de modo que un curso cuya indexación falló se
  autorrepara por cron sin tener que volver a guardar la configuración.

## [4.4.0] — 2026-07-08

### Añadido

- **Modelo de licencias por sitio.** El asistente requiere ahora una clave de licencia
  verificada sin conexión (RSA‑SHA256) y ligada al `wwwroot` de la instalación: una
  clave emitida para un sitio no valida en otro. Estados: válida, expirada, inválida o
  no configurada.
  - Nueva sección "Licencia" en los ajustes del plugin, con el campo de clave y un
    indicador de estado en color.
  - `classes/license/validator.php` con la clave pública embebida (segura de
    distribuir); la clave privada `license_private.key` nunca se distribuye.
  - `generate_license.php`: herramienta interna del proveedor para emitir y rotar claves
    (excluida del ZIP del cliente).
  - `build_zip.ps1`: empaquetado que excluye con guardia dura la clave privada y el
    generador, e incluye `vendor/` (extractor de PDF).
- **Aplicación del gate**: sin licencia válida, el bloque muestra un aviso en lugar del
  chat (el motivo detallado solo a gestores) y el orquestador rechaza cualquier turno de
  IA como último backstop, incluso si se invoca el servicio externo directamente.

## [4.3.0] — 2026-07-08

### Añadido

- **Activar/desactivar cada agente por curso.** Un curso puede dejar solo el tutor o
  solo el asistente de plataforma; con un único agente activo se omite el enrutador y
  todas las preguntas van directas a ese agente. Debe quedar al menos uno activo.
- **Troceado con conciencia de página y jerarquía** para la base de conocimiento: cada
  fragmento lleva una miga `[Section: Unidad › Paso › ... | p. N]` (los PDF se extraen
  por páginas con Smalot), de modo que el tutor cita la unidad, sección y página reales.
- **Recuperación reforzada**: realce y reserva de plazas para los fragmentos cuya sección
  coincide con la pregunta (garantiza que la sección titulada con el término se recupere
  siempre) y recuperación con contexto de la conversación para los seguimientos cortos.
- **Inspector de recuperación** en las herramientas de prueba: muestra, para una pregunta
  y un curso, qué fragmentos recibiría el tutor con su miga, puntuación y si van marcados
  como enviados.

### Cambiado

- **Prompt del tutor** mucho más estricto: usa solo los extractos, prohibido el
  conocimiento externo o inventar ubicaciones; si no está en el material, lo dice.
- **Enrutador reforzado**: las preguntas sobre dónde está un tema dentro de los
  documentos/guía (en qué unidad/capítulo/página, qué dice la guía) van al tutor, no al
  asistente de plataforma.
- **Ajustes**: los campos de credenciales cambian en vivo según el proveedor
  seleccionado (mediante `hide_if`), sin necesidad de guardar; un modelo de otro
  proveedor ya no queda "pegado" al cambiar de proveedor. Nº de fragmentos recuperados
  por defecto 5 → 8.

### Corregido

- Página de herramientas de prueba: se cargaba `adminlib.php` que faltaba
  (`admin_externalpage_setup()` indefinido).

### Seguridad

- **Acceso entre cursos**: las herramientas que reciben un `cmid` ahora comprueban que
  la actividad pertenece al curso vinculado a la sesión, evitando que un alumno consulte
  actividades de otro curso a través del asistente.
- Se retiró el endpoint MCP remoto (ahora responde 410) y toda su configuración y
  tokens: desde la versión multi‑proveedor las herramientas de Moodle se ejecutan
  localmente en proceso. Se eliminó también código obsoleto (gestor de uso y límite
  diario heredados).

## [4.2.0] — 2026-07-07

- Versión base multi‑proveedor (OpenAI, Anthropic, Gemini, DeepSeek) con base de
  conocimiento local para el tutor y ejecución local de las herramientas de Moodle.
