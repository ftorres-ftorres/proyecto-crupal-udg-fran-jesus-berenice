# Portal de colonos · Provenza Residencial

Prueba de concepto en Drupal 11 (español de México) para Colonos del Condominio Residencial AC.

- Sitio local: https://provenza.ddev.site
- Correo de prueba: https://provenza.ddev.site:8026 (Mailpit)
- Requisitos: [docs/requisitos-y-navegacion-sitio-colonos-provenza.md](docs/requisitos-y-navegacion-sitio-colonos-provenza.md)

## Arranque

Se necesita [DDEV](https://ddev.com) y OrbStack (o Docker).

```bash
ddev start
ddev launch
```

Si la base está vacía:

```bash
ddev drush site:install standard --locale=es --existing-config -y \
  --account-name=webmaster --account-pass='Provenza2026!' \
  --account-mail=webmaster@provenza.local \
  --site-name='Portal de colonos · Provenza Residencial'
ddev drush en provenza_core provenza_demo -y
# Con --existing-config los hooks de instalación no crean contenido:
ddev drush php:eval '\Drupal::classResolver(\Drupal\provenza_core\Install\SiteInstaller::class)->install(); provenza_core_form_pages();'
ddev drush php:eval '\Drupal::classResolver(\Drupal\provenza_demo\DemoContentGenerator::class)->generate();'
ddev drush php:eval 'node_access_rebuild();'
ddev drush cr
```

El módulo `provenza_core` crea tipos de contenido, roles, formularios y menús. `provenza_demo` carga casas, cuentas, tarifas e historiales de ejemplo.

## Cuentas de demostración

Contraseña de todas (salvo que TFA pida un código): `Provenza2026!`

| Correo | Rol | Para qué sirve |
|---|---|---|
| webmaster@provenza.local | Webmaster (uid 1, también Administrador) | Configuración del sitio |
| ana.titular@provenza.local | Residente titular | Solicitudes, reservaciones, visitas, quejas |
| luis.familiar@provenza.local | Residente familiar | Mismas gestiones, sin administrar el domicilio |
| maria.inquilina@provenza.local | Familiar / inquilino | Casa en Av. Viñedos 3 |
| caseta@provenza.local | Caseta / Seguridad | https://provenza.ddev.site/caseta |
| mantenimiento@provenza.local | Mantenimiento | Solicitudes asignadas |
| administracion@provenza.local | Administración | Bandeja, residentes, plantillas de correo |
| mesa@provenza.local | Mesa directiva | Reportes de solo lectura |

TFA (TOTP) está activado y es obligatorio para los roles Administración y Webmaster en un inicio de sesión con contraseña. En local puede entrar con `ddev drush uli --name=administracion`.

reCAPTCHA v3 está activo en registro, contacto y quejas (claves de prueba de Google en local). Un SMTP de dominio autenticado quedó fuera de esta prueba (Mailpit).

## Recorridos

1. Reportar alumbrado: iniciar sesión como Ana → Solicitudes → Nueva solicitud.
2. Reservar el salón: Instalaciones → Salón de fiestas → calendario → solicitar reservación.
3. Alberca: Instalaciones → Alberca (reglas, horario 7:00–20:00, pase de invitado $220).
4. Pre-registrar visita: Seguridad → Registrar visita → pase QR → validar en `/caseta`.
5. Queja confidencial: Quejas → Reporte confidencial. Solo Administración ve la identidad.
6. Tarifas: Comunidad → Cuotas y tarifas (montos de $200 a $480).
7. Tutorial: pie de página → Así lo construimos (no aparece en el menú principal, ni en `/buscar`, ni en `/mapa-del-sitio`, ni en `/sitemap.xml`; las fichas van con `noindex`).

## Comandos útiles

```bash
ddev drush cr
ddev drush cex -y
ddev drush cim -y
ddev drush php:eval '\Drupal::classResolver(\Drupal\provenza_core\Install\SiteInstaller::class)->install();'
ddev drush php:eval '\Drupal::classResolver(\Drupal\provenza_demo\DemoContentGenerator::class)->generate();'
```

Configuración exportada en `config/sync`. Archivos privados en `private/`.

## Tema Provenza

Tema propio en `web/themes/custom/provenza` (base `stable9`).

- Tipografías: Gilda Display (títulos), Bodoni Moda cursiva (marca y énfasis) y Jost (texto). Paleta olivo, negro y blanco. Iconos Hugeicons.
- Portada con video, galería de instalaciones y agenda de avisos y eventos.
- Login a pantalla dividida, calendario de eventos y de disponibilidad del salón (al tocar un día libre se abre la reservación con la fecha).
- **Mi panel** (`/mi-panel`): resumen tipo widgets y dock lateral con todos los trámites. Sin sesión, cualquier trámite manda al inicio de sesión y regresa.

### Multimedia

- Fotos y clips de video de [Pexels](https://www.pexels.com) (licencia libre), recortados y corregidos de color con ffmpeg y exportados a WebP/MP4.
- Video del hero (`media/provenza-hero.mp4`, 39 s): montaje de 12 clips con fundidos, narración en español de México y música original compuesta con ffmpeg (ducking y normalización a -16 LUFS).
- Animaciones: entrada en cortina, galería expandible, barra flotante y vidrio líquido con refracción SVG. Respetan `prefers-reduced-motion`.