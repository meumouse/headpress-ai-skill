# Headpress AI skill

[English](README.md) · [Português (Brasil)](README.pt-BR.md) · **Español**

Una skill de agente (`headpress-store`) que enseña a los asistentes de IA a construir una **tienda
WooCommerce headless** sobre el stack de Headpress **de cero al despliegue**. También enseña a
ampliarla con plugins add-on a medida para las necesidades específicas de cada cliente.

El stack que cubre:

| Pieza | Repositorio | Función |
|---|---|---|
| **Headpress** | [`meumouse/headpress`](https://github.com/meumouse/headpress) | Plugin de WordPress: REST `headpress/v1`, extensiones de la Store API (`extensions.headpress`), modo headless, webhooks de revalidación, módulos |
| **SDK** `@meumouse.com/headpress` | [`meumouse/headpress-sdk-js`](https://github.com/meumouse/headpress-sdk-js) | Cliente TypeScript, helpers para Next.js, API de plugins, registry de shadcn |
| **Storefront starter** | [`meumouse/storefront-starter-headpress-nextjs`](https://github.com/meumouse/storefront-starter-headpress-nextjs) | Storefront en Next.js 16 + React 19 + Tailwind 4 |

La skill resume la documentación oficial de esos tres repositorios. También recoge las lecciones de
una migración real en producción: una tienda WooCommerce con tema clásico que pasó a headless. Los
repositorios siguen siendo la fuente de verdad. La skill le dice al agente **qué leer, en qué orden,
dónde construir cada cosa y qué suele fallar**.

Versiones vigentes cuando se escribió la skill (nov. 2026): Headpress 1.8.0, SDK 0.1.2, starter 0.2.1.

---

## Índice

- [Qué hace la skill](#qué-hace-la-skill)
- [Instalación](#instalación)
- [Uso](#uso)
- [Estructura del repositorio](#estructura-del-repositorio)
- [El flujo de trabajo](#el-flujo-de-trabajo)
- [Reglas de oro](#reglas-de-oro)
- [Plantilla de add-on](#plantilla-de-add-on)
- [Evals](#evals)
- [Empaquetado](#empaquetado)
- [Mantenimiento de la skill](#mantenimiento-de-la-skill)

---

## Qué hace la skill

Cuando una tarea toca el stack de Headpress, el agente carga `SKILL.md`. Si hace falta, abre también
el archivo de referencia de la parte en la que trabaja. Con ello, el agente puede:

- **Planificar el proyecto:** dominios, idioma, mapa de rutas, pasarelas, envío, cuotas, campos del
  checkout, SEO, analítica y consentimiento, alojamiento. Una tabla indica dónde va cada
  funcionalidad: configuración pública, extensión de la Store API, acción de carrito, campo de
  checkout, ruta REST, módulo de integración, storefront o núcleo de Headpress.
- **Configurar el backend:** plugins, ajustes en Headpress → Storefront (URL del storefront,
  orígenes, mapa de rutas, namespaces de CORS, webhook, claves de API), módulos, constantes de
  `wp-config.php`, ajuste del servidor, seguridad y un modo headless que nunca perjudica el SEO.
- **Construir el storefront:** estructura del starter y reglas de capas, variables de entorno (de
  build y de runtime), integración con el SDK, páginas y rutas, tema, i18n, registro de pagos,
  correos, caché, comportamiento cuando el backend cae, SEO y analítica.
- **Escribir plugins add-on a medida** que registran módulos de Headpress con ajustes, rutas,
  extensiones de la Store API, acciones de carrito, eventos, jobs, health checks, captcha, rate
  limits y pantallas en el admin. El plugin correspondiente del SDK (`definePlugin()` con
  declaration merging) se crea a la vez.
- **Integrar pasarelas de pago** con el checkout de la Store API, tanto en WordPress como en el
  storefront: redirección alojada, Pix o boleto con polling y campos de tarjeta tokenizados en el
  navegador.
- **Desplegar y salir a producción:** imagen Docker y CI, proxy inverso y Cloudflare, migración por
  oleadas, runbook de cambio, smoke test, plan de rollback, monitorización y retirada del sistema
  anterior.
- **Evitar fallos conocidos:** un catálogo de incidentes reales agrupados por síntoma (CORS,
  sesiones, redirecciones, SEO, caché, pagos, despliegue, seguridad).

La skill se activa cuando el usuario menciona Headpress, una tienda WooCommerce o Next.js headless,
el starter, el SDK, `extensions.headpress`, `headpress/v1`, Cart-Token, una migración de storefront
o un plugin "integrado con Headpress". También se activa con una pregunta sobre una sola pieza, como
un checkout, un medio de pago, un despliegue, un error de CORS o un add-on.

---

## Instalación

La carpeta de la skill debe llamarse como la skill: **`headpress-store`**.

### Claude Code: todos los proyectos (nivel de usuario)

```powershell
git clone https://github.com/meumouse/headpress-ai-skill.git "$env:USERPROFILE\.claude\skills\headpress-store"
```

```bash
git clone https://github.com/meumouse/headpress-ai-skill.git ~/.claude/skills/headpress-store
```

### Claude Code: un solo proyecto

Clónala o cópiala en `.claude/skills/headpress-store/` en la raíz del proyecto, por ejemplo en el
repositorio del storefront o del add-on. Haz commit allí si todo el equipo debe recibirla.

### Apps de Claude (claude.ai / escritorio)

Genera el paquete `.skill` (consulta [Empaquetado](#empaquetado)) y súbelo en los ajustes de Skills
de la app.

### Otros agentes (Codex, Cursor, Copilot…)

Apunta el agente a la skill desde el `AGENTS.md` del proyecto (o el archivo de reglas de la
herramienta), por ejemplo:

```markdown
For anything about the Headpress stack, read `path/to/headpress-store/SKILL.md` first and the
reference file it points to for the task.
```

### Actualización

```powershell
git -C "$env:USERPROFILE\.claude\skills\headpress-store" pull
```

---

## Uso

No hay que llamar a nada: describe la tarea y el agente carga la skill. Ejemplos de peticiones:

- "Empieza una tienda headless nueva con Headpress para `tienda.com`, con Pix y tarjeta vía Mercado Pago."
- "Crea un plugin integrado con Headpress que permita al cliente escribir un mensaje de regalo en
  el carrito."
- "Escribe el runbook de despliegue y cambio: WordPress detrás de Cloudflare, storefront en Docker."
- "Después de actualizar WooCommerce, el carrito del storefront da un error de CORS. ¿Cómo lo arreglo?"
- "Migra esta tienda WooCommerce con tema clásico al storefront de Headpress sin perder SEO."
- "Haz que la pasarela de PagBank funcione en el checkout headless."

En Claude Code también puedes invocarla directamente con `/headpress-store`.

---

## Estructura del repositorio

```
SKILL.md                         punto de entrada: topología, repositorios, reglas de oro, fases 0–7, definición de terminado
references/
  contracts.md                   contratos WP ↔ storefront: ámbitos y claves REST, store config,
                                 extensions.headpress, acciones de carrito, datos del checkout, Cart-Token,
                                 CORS, rate limits, webhook de revalidación y vocabulario de tags, eventos
  wordpress-setup.md             instalación y configuración del backend, módulos, constantes de wp-config,
                                 modo headless y SEO, ajuste del servidor, seguridad, comprobaciones con curl
  storefront.md                  estructura y reglas del starter, variables de entorno, SDK, rutas, caché,
                                 SEO, analítica, correos, componentes del registry, plugins del SDK, tests
  addon-plugins.md               plugins add-on: esqueleto, API de módulos, schema de ajustes, rutas,
                                 Store API, eventos, jobs, health checks, UI en el admin, integración con
                                 plugins de terceros, importador de datos heredados, plugin del SDK,
                                 tests y build
  payment-gateways.md            procesamiento de pagos en la Store API, config pública del método,
                                 rutas extra, webhooks, providers de pago en el storefront, polling y
                                 reintento, seguridad
  deploy-golive.md               Docker/CI, proxy y Cloudflare, caché, monitorización, oleadas de migración,
                                 runbook de cambio, smoke test, rollback y retirada del sistema anterior
  pitfalls.md                    incidentes reales y sus soluciones, agrupados por síntoma
assets/
  addon-template/                un add-on "Acme" funcional (solicitudes de presupuesto) para copiar en nuevos add-ons
evals/                           casos de prueba de la skill (consulta Evals)
```

`SKILL.md` es corto y le dice al agente qué referencia abrir. Las referencias solo se cargan cuando
la tarea las necesita, así que la skill ocupa poco contexto en preguntas pequeñas.

---

## El flujo de trabajo

`SKILL.md` lleva cada proyecto por ocho fases. Cada fase termina con una comprobación que el agente
puede ejecutar:

| Fase | Qué ocurre | Comprobación |
|---|---|---|
| 0. Descubrimiento | Dominios, idioma, mapa de rutas, pasarelas, envío, campos del checkout, SEO, analítica, alojamiento; dónde va cada funcionalidad | Decisiones anotadas en el archivo de plan del proyecto |
| 1. Backend | WordPress + WooCommerce + Headpress + integraciones, ajustes del Storefront, módulos, claves de API | `GET /wp-json/headpress/v1/store/config` devuelve el mapa de rutas; health checks sin errores |
| 2. Storefront | Starter, `npm run demo`, env, rutas, marca mediante tokens, páginas de la tienda | `check:routes` y `npm run verify` pasan; ningún error de CORS |
| 3. Integraciones | Pagos, envío, cuotas, analítica, correos | Un pedido de prueba en sandbox por pasarela; una compra por herramienta de analítica |
| 4. Add-on | Funcionalidades específicas del cliente como módulos de Headpress en un plugin aparte, más el plugin del SDK | Los módulos activan y desactivan sus rutas; el ZIP de release se instala en un sitio limpio |
| 5. SEO y migración | Head de SEO, redirecciones, robots y sitemap, mapa de URLs heredadas, importación de datos | Rastreo de las URLs antiguas: cada URL da 200 o un único 301 |
| 6. Despliegue | Imagen Docker, env de build y de runtime, tags en CI, proxies de confianza, Redis | La imagen arranca solo con secretos de runtime |
| 7. Salida a producción | Runbook de cambio, smoke test, 302 → 301, seguimiento heredado apagado, monitorización, retirada del sistema anterior | Smoke test en verde; webhooks entregados |

La skill termina con una lista de **definición de terminado** para todo el proyecto.

---

## Reglas de oro

Se aplican en todas las fases. El texto completo está en `SKILL.md`.

1. **El servidor decide, el storefront muestra.** Precios, descuentos, cuotas, envío y stock los
   calcula WordPress y el storefront solo los muestra.
2. **API primero.** Toda capacidad es una ruta REST o una extensión de la Store API antes de ser
   una pantalla.
3. **No dupliques WooCommerce.** Amplía la Store API (`extensions.headpress.<module>`); nunca la
   reimplementes.
4. **Amplía, no edites el núcleo.** Las funcionalidades específicas del cliente van en un plugin
   add-on. Un punto de extensión que falta se añade al propio Headpress, en un cambio aparte y
   versionado.
5. **Los contratos cruzan repositorios.** Los IDs de módulos, el formato de la config, el formato de
   las rutas, las claves de las extensiones y los tags del webhook los consumen otros repositorios.
   Cambiar un formato es un cambio incompatible.
6. **Nunca perjudiques el SEO del storefront.** Nunca `blog_public = 0` en el host de WordPress.
7. **El dinero son enteros en unidades menores** (céntimos), de extremo a extremo.
8. **Los secretos nunca llegan al navegador**, y los datos de tarjeta nunca pasan por los servidores.
9. **Una caída no es un 404.** Un objeto que no existe da `notFound()`; un backend que no responde
   da un estado de no disponible.
10. **Higiene de los repositorios.** Escribe en inglés, no añadas atribución a IA, sigue el estilo de
    cada repositorio y no ejecutes comandos destructivos sin que te lo pidan.

---

## Plantilla de add-on

`assets/addon-template/` es un add-on mínimo con un módulo (**solicitudes de presupuesto**). Muestra
todos los puntos de extensión habituales:

| Archivo | Muestra |
|---|---|
| `acme-store-addon.php` | Cabecera del plugin con `Requires Plugins: headpress, woocommerce`, autoloader PSR-4, declaración de HPOS, arranque en `plugins_loaded` (11), aviso cuando Headpress no está activo |
| `src/Plugin.php` | Registro de módulos en `Headpress/Modules/Register`, con try/catch por módulo para que un módulo roto nunca tumbe el sitio |
| `src/Modules/Quotes/Quotes_Module.php` | Schema de ajustes (público + privado), `public_config` calculado, extensión de producto en la Store API, evento propio, job en segundo plano, health check, ruta con captcha |
| `src/Modules/Quotes/Rest/Quote_Post.php` | Ruta de ámbito `store` con `args()` + `schema()`, rate limits, estados de error reales (404 / 422 / 429), `Cache-Control: no-store` |
| `storefront/acme-plugin.ts` | `definePlugin()` del SDK con método tipado, mensajes de i18n y declaration merging en `ModuleConfigMap` |
| `scripts/build.mjs` | ZIP de release: comprueba la versión (cabecera = constante = `package.json`), comprueba el CHANGELOG, usa una allowlist y separadores `/` |
| `tests/smoke.php` | Smoke test con `wp eval-file`: visibilidad de la config, rutas 400/404/201, extensión de la Store API, limpieza al final |

Para empezar un add-on real, sigue `assets/addon-template/README.md` (en inglés). En resumen: cambia
el nombre del namespace `Acme`, el prefijo `acme`, el slug `acme-store-addon` y las constantes
`ACME_STORE_ADDON_*`. Después sustituye el módulo Quotes por los módulos de la tienda y ejecuta el
smoke test en un sitio de desarrollo.

> La plantilla pasa `php -l`, pero todavía no se ha ejecutado en un sitio WordPress real. Ejecuta
> `tests/smoke.php` en un sitio de desarrollo antes de fiarte de ella.

---

## Evals

`evals/` contiene casos de prueba en el formato que lee `claude plugin eval`. Cada caso es una
carpeta con:

```
evals/<case-name>/
  prompt.md      ajustes de la ejecución + la petición del usuario
  case.yaml      nombre y descripción de un buen resultado
  graders/       una afirmación verificable por archivo
```

| Caso | Petición (resumen) | Qué comprueban los graders |
|---|---|---|
| `gift-message-addon` | Crear un plugin integrado con Headpress para un mensaje de regalo de 200 caracteres en el carrito, visible en el pedido y en los correos, más el código del storefront | Registro como módulo de Headpress, escritura en el carrito vía Store API / Cart-Token, lectura vía `extensions.headpress`, validación en el servidor, meta del pedido compatible con HPOS en el admin y en los correos, ninguna cookie enviada a la Store API, código en inglés, uso del cliente / Cart-Token / componentes / i18n del propio starter |
| `cloudflare-docker-golive-runbook` | Runbook de despliegue y cambio: WordPress detrás de Cloudflare, storefront en Docker | `blog_public` sin tocar, proxies de confianza, `NEXT_PUBLIC_*` de build, secreto del webhook de revalidación, mapa de 301, Cloudflare sin caché en las rutas dinámicas, smoke test con pedido, rollback, origen del storefront en CORS, tienda clásica intacta hasta el cambio, enlaces de correos ya enviados, ningún origen de desarrollo en producción |
| `store-api-cors-after-woo-update` | Error de CORS en el carrito tras actualizar WooCommerce a la 11.3.1 | Diagnóstico correcto (sin credentials para el origen del storefront), `credentials: "omit"` + Cart-Token, ninguna "solución" insegura, origen del storefront en Headpress, Cart-Token en las cabeceras permitidas y expuestas, una forma de confirmar la solución |

Ejecútalos con Claude Code (v2.1.269 o posterior) desde la raíz del repositorio. Cada caso se
ejecuta con y sin la skill, y el informe se guarda en `evals/results/`:

```powershell
claude plugin eval . --allow-tools Write,Edit,Bash
```

Son ejecuciones reales y facturadas del modelo. Cuando un caso se ejecuta dentro del repositorio de
Headpress, el agente puede leer la documentación del propio repositorio, así que un caso puede pasar
sin la skill. Usa la comparación con/sin skill para ver lo que aporta.

También puedes ejecutar los casos con la skill `skill-creator`. Guarda los resultados en una carpeta
hermana `headpress-store-workspace/` y abre un visor para revisarlos.

---

## Empaquetado

Un archivo `.skill` es un ZIP con la carpeta de la skill en la raíz. Genéralo desde Git para incluir
solo archivos versionados, dejando fuera los README y los evals:

```powershell
git archive --format=zip --prefix=headpress-store/ -o headpress-store.skill HEAD SKILL.md references assets
```

---

## Mantenimiento de la skill

- **Mantenla sincronizada con los repositorios.** Cuando cambie un contrato de Headpress, del SDK o
  del starter, actualiza el archivo de referencia correspondiente. Esto incluye IDs de módulos,
  formato de la store config, rutas, claves de `extensions.headpress`, tags del webhook y variables
  de entorno. Actualiza también las versiones citadas al principio de `SKILL.md`.
- **Las divergencias conocidas** en la documentación de los repositorios están listadas en
  `SKILL.md` ("Where the knowledge lives"). Elimina la entrada cuando se corrija el repositorio de
  origen:
  - El `AGENTS.md` de Headpress llama al SDK `@headpress/sdk`; el nombre publicado es `@meumouse.com/headpress`.
  - El `AGENTS.md` de Headpress menciona un directorio `Addons/` y `headpress_register_event()`, que no existen.
  - El `AGENTS.md` del starter apunta a `src/i18n/messages/`; los diccionarios están en `src/i18n/<domain>.ts`.
- **Los incidentes nuevos van a `references/pitfalls.md`.** Agrupa cada uno por síntoma, con la
  causa y la solución. Escríbelo de forma genérica: sin nombres de clientes, dominios, prefijos ni
  credenciales, porque la skill es compartida.
- **Comprueba antes de escribir.** Todo hook, filtro, función, ruta y variable de entorno citado en
  la skill debe existir en el código fuente. Compruébalo con `grep` en el repositorio en vez de
  escribir de memoria.
- **Mantén `SKILL.md` ligero.** Se carga en cada activación. Los detalles van en `references/`, y
  `SKILL.md` solo dice cuándo abrir cada uno.
- **Vuelve a ejecutar los evals** después de un cambio relevante, y añade un caso cuando un tipo de
  tarea empiece a repetirse.
- Todo en este repositorio está escrito en inglés, sin líneas de atribución a IA en los archivos ni
  en los commits. Las únicas excepciones son los README traducidos (`README.pt-BR.md`,
  `README.es-ES.md`). Mantenlos sincronizados cada vez que cambie `README.md`.
