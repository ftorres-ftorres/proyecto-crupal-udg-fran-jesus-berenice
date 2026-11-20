<?php

namespace Drupal\provenza_demo;

use Drupal\file\FileInterface;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\User;
use Drupal\Core\File\FileSystemInterface;

/**
 * Creates sample residents, houses and content for the PoC.
 */
class DemoContentGenerator {

  protected string $password = 'Provenza2026!';

  /**
   * Generates all sample data (idempotent by title/mail).
   */
  public function generate(): void {
    $houses = $this->houses();
    $users = $this->users($houses);
    $this->linkHouseholds($houses, $users);
    $rates = $this->rates();
    $this->facilities($rates);
    $this->community();
    $this->tracking($houses, $users);
    $this->tutorial();
    node_access_rebuild(TRUE);
  }

  /**
   * Sample houses.
   */
  protected function houses(): array {
    $data = [
      ['Calle Provenza', '12'],
      ['Calle Provenza', '14'],
      ['Av. Viñedos', '3'],
      ['Cerrada Toscana', '8'],
      ['Privada Chianti', '21'],
    ];
    $nodes = [];
    foreach ($data as [$street, $num]) {
      $title = $street . ' ' . $num;
      $nodes[$title] = $this->node('domicilio', $title, [
        'field_calle' => $street,
        'field_numero' => $num,
        'field_estado_domicilio' => 'activo',
      ]);
    }
    return $nodes;
  }

  /**
   * Demo accounts, one per role.
   */
  protected function users(array $houses): array {
    $admin = User::load(1);
    if ($admin && !$admin->hasRole('webmaster')) {
      $admin->addRole('webmaster');
      $admin->save();
    }

    $specs = [
      'ana.titular@provenza.local' => [
        'name' => 'ana.titular',
        'display' => 'Ana López',
        'roles' => ['residente_titular'],
        'house' => 'Calle Provenza 12',
        'rel' => 'titular',
        'phone' => '3311111111',
      ],
      'luis.familiar@provenza.local' => [
        'name' => 'luis.familiar',
        'display' => 'Luis López',
        'roles' => ['residente_familiar'],
        'house' => 'Calle Provenza 12',
        'rel' => 'familiar',
        'phone' => '3311111112',
      ],
      'maria.inquilina@provenza.local' => [
        'name' => 'maria.inquilina',
        'display' => 'María Soto',
        'roles' => ['residente_familiar'],
        'house' => 'Av. Viñedos 3',
        'rel' => 'inquilino',
        'phone' => '3322222222',
      ],
      'caseta@provenza.local' => [
        'name' => 'caseta',
        'display' => 'Guardia de caseta',
        'roles' => ['caseta'],
        'phone' => '3333333333',
      ],
      'mantenimiento@provenza.local' => [
        'name' => 'mantenimiento',
        'display' => 'Jorge Mantenimiento',
        'roles' => ['mantenimiento'],
        'phone' => '3344444444',
      ],
      'administracion@provenza.local' => [
        'name' => 'administracion',
        'display' => 'Patricia Administración',
        'roles' => ['administracion'],
        'phone' => '3355555555',
      ],
      'mesa@provenza.local' => [
        'name' => 'mesa',
        'display' => 'Comité Mesa Directiva',
        'roles' => ['mesa_directiva'],
        'phone' => '3366666666',
      ],
    ];
    $users = [];
    foreach ($specs as $mail => $spec) {
      $existing = user_load_by_mail($mail);
      if ($existing) {
        $users[$mail] = $existing;
        continue;
      }
      $user = User::create([
        'name' => $spec['name'],
        'mail' => $mail,
        'pass' => $this->password,
        'status' => 1,
        'langcode' => 'es',
        'preferred_langcode' => 'es',
      ]);
      foreach ($spec['roles'] as $role) {
        $user->addRole($role);
      }
      $user->set('field_nombre_completo', $spec['display']);
      $user->set('field_telefono', $spec['phone']);
      $user->set('field_contacto_emergencia_nom', 'Contacto de emergencia');
      $user->set('field_contacto_emergencia_tel', '3310000000');
      $user->set('field_notif_solicitudes', 1);
      $user->set('field_notif_avisos', 1);
      $user->set('field_notif_reservaciones', 1);
      if (!empty($spec['house']) && isset($houses[$spec['house']])) {
        $user->set('field_domicilio', $houses[$spec['house']]->id());
        $user->set('field_relacion', $spec['rel'] ?? 'titular');
      }
      if ($mail === 'ana.titular@provenza.local') {
        $car = Paragraph::create([
          'type' => 'vehiculo',
          'field_placa' => 'JAG-1234',
          'field_marca' => 'Volkswagen',
          'field_color' => 'Gris',
          'field_tag_acceso' => 'TAG-1001',
        ]);
        $car->save();
        $pet = Paragraph::create([
          'type' => 'mascota',
          'field_nombre_mascota' => 'Luna',
          'field_tipo_mascota' => 'Perro',
          'field_notas_mascota' => 'Labrador',
        ]);
        $pet->save();
        $user->set('field_vehiculos', [$car]);
        $user->set('field_mascotas', [$pet]);
      }
      $user->save();
      $users[$mail] = $user;
    }
    return $users;
  }

  /**
   * Sets house titular.
   */
  protected function linkHouseholds(array $houses, array $users): void {
    if (isset($houses['Calle Provenza 12'], $users['ana.titular@provenza.local'])) {
      $houses['Calle Provenza 12']->set('field_titular', $users['ana.titular@provenza.local']->id());
      $houses['Calle Provenza 12']->save();
    }
    if (isset($houses['Av. Viñedos 3'], $users['maria.inquilina@provenza.local'])) {
      $houses['Av. Viñedos 3']->set('field_titular', $users['maria.inquilina@provenza.local']->id());
      $houses['Av. Viñedos 3']->save();
    }
  }

  /**
   * Sample rates from the brief.
   */
  protected function rates(): array {
    $items = [
      'cuota' => ['Cuota mensual de mantenimiento', 380],
      'renta_salon' => ['Renta del salón de fiestas', 480],
      'deposito_salon' => ['Depósito en garantía del salón de fiestas', 250],
      'pase_alberca' => ['Pase de invitado a la alberca (por persona)', 220],
      'tag_extra' => ['Tag de acceso adicional', 200],
      'tag_repo' => ['Reposición de tag de acceso', 320],
      'residuos' => ['Recolección especial de residuos', 430],
      'alberca_evento' => ['Uso de la alberca para evento familiar', 350],
    ];
    $nodes = [];
    foreach ($items as $code => [$concepto, $monto]) {
      $nodes[$code] = $this->node('tarifa', $concepto, [
        'field_concepto' => $concepto,
        'field_monto' => $monto,
        'field_codigo_tarifa' => $code,
      ]);
    }
    return $nodes;
  }

  /**
   * Seven facilities.
   */
  protected function facilities(array $rates): void {
    $photo = $this->placeholder('instalacion');
    $list = [
      [
        'Salón de fiestas', 'salon',
        'Salón climatizado para eventos de colonos, con cocina de apoyo.',
        "Capacidad 80 personas.\nHorario: 10:00 a 23:00.\nSe requiere reservación aprobada y depósito.",
        '10:00–23:00', 80,
        ['renta_salon', 'deposito_salon'],
      ],
      [
        'Gimnasio', 'gym',
        'Gimnasio equipado para uso de colonos. Reporta fallas de aparatos en Solicitudes.',
        "Uso con toalla y calzado deportivo.\nMenores acompañados.\nHorario: 6:00 a 22:00.",
        '06:00–22:00', 20, [],
      ],
      [
        'Alberca', 'alberca',
        'Alberca semiolímpica. Revisa reglas, horarios, pases de invitado y cierres por mantenimiento.',
        "Baño obligatorio antes de entrar.\nNiños con adulto.\nInvitados con pase pagado.\nCierres de mantenimiento: lunes 8:00–12:00.",
        '07:00–20:00', 40,
        ['pase_alberca', 'alberca_evento'],
      ],
      [
        'Trotapista y áreas verdes', 'trotapista',
        'Circuito de trote y áreas verdes interiores.',
        'Uso peatonal. Mascotas con correa. No se permiten vehículos.',
        '05:00–22:00', 0, [],
      ],
      [
        'Juegos infantiles', 'juegos',
        'Área de juegos para niñas y niños.',
        'Uso de 8:00 a 20:00. Adulto responsable presente.',
        '08:00–20:00', 0, [],
      ],
      [
        'Fuentes', 'fuentes',
        'Fuentes ornamentales del acceso y plaza central.',
        'No introducirse al agua. Reporta fugas o fallas de iluminación.',
        '24 h', 0, [],
      ],
      [
        'Jardines', 'jardines',
        'Jardines comunes y camellones.',
        'No extraer plantas. Las jornadas de jardinería se anuncian en el calendario.',
        '24 h', 0, [],
      ],
    ];
    foreach ($list as $row) {
      [$title, $code, $body, $rules, $hours, $cap, $rate_codes] = $row;
      $tids = [];
      foreach ($rate_codes as $c) {
        if (isset($rates[$c])) {
          $tids[] = $rates[$c]->id();
        }
      }
      $fields = [
        'body' => ['value' => '<p>' . $body . '</p>', 'format' => 'basic_html'],
        'field_codigo_instalacion' => $code,
        'field_reglas' => ['value' => '<p>' . nl2br($rules) . '</p>', 'format' => 'basic_html'],
        'field_horarios' => $hours,
        'field_capacidad' => $cap,
        'field_tarifa' => $tids,
      ];
      if ($photo) {
        $fields['field_fotos_publicas'] = [['target_id' => $photo, 'alt' => $title]];
      }
      $this->node('instalacion', $title, $fields);
    }
  }

  /**
   * Announcements, events, docs, FAQs, directory.
   */
  protected function community(): void {
    $cat = $this->tid('categorias_aviso', 'Seguridad');
    $this->node('aviso', 'Fumigación de jardines el sábado', [
      'body' => ['value' => '<p>Se fumigarán jardines comunes. Guarda a tus mascotas de 8:00 a 12:00.</p>', 'format' => 'basic_html'],
      'field_categoria_aviso' => $cat,
      'field_urgente' => 1,
      'field_enviar_correo' => 0,
    ]);
    $this->node('aviso', 'Horario de alberca en temporada', [
      'body' => ['value' => '<p>La alberca abre de 7:00 a 20:00.</p>', 'format' => 'basic_html'],
      'field_categoria_aviso' => $this->tid('categorias_aviso', 'General'),
      'field_urgente' => 0,
    ]);

    $this->node('evento', 'Asamblea ordinaria', [
      'body' => ['value' => '<p>Asamblea en el salón de fiestas.</p>', 'format' => 'basic_html'],
      'field_tipo_evento' => $this->tid('tipos_evento', 'Asamblea'),
      'field_ubicacion' => 'Salón de fiestas',
      'field_fecha_evento' => $this->smartDate('+10 days 10:00', 120),
    ]);
    $this->node('evento', 'Jornada de jardinería', [
      'body' => ['value' => '<p>Poda de camellones.</p>', 'format' => 'basic_html'],
      'field_tipo_evento' => $this->tid('tipos_evento', 'Jornada de jardinería'),
      'field_ubicacion' => 'Jardines',
      'field_fecha_evento' => $this->smartDate('+4 days 08:00', 180),
    ]);
    $this->node('evento', 'Recolección de residuos voluminosos', [
      'body' => ['value' => '<p>Saca muebles a partir de las 7:00.</p>', 'format' => 'basic_html'],
      'field_tipo_evento' => $this->tid('tipos_evento', 'Recolección de residuos'),
      'field_fecha_evento' => $this->smartDate('+2 days 07:00', 240),
    ]);

    $file = $this->textFile('reglamento-interno.txt', "Reglamento interno de Provenza Residencial (documento de ejemplo).\n");
    $this->node('documento', 'Reglamento interno', [
      'body' => ['value' => '<p>Reglamento de convivencia y uso de áreas comunes.</p>', 'format' => 'basic_html'],
      'field_tipo_documento' => $this->tid('tipos_documento', 'Reglamento'),
      'field_audiencia' => ['visitante', 'residente_titular', 'residente_familiar'],
      'field_archivo' => $file ? ['target_id' => $file] : NULL,
    ]);
    $acta = $this->textFile('acta-asamblea-2026.txt', "Acta de asamblea ordinaria (ejemplo).\n");
    $this->node('documento', 'Acta de asamblea ordinaria 2026', [
      'field_tipo_documento' => $this->tid('tipos_documento', 'Acta de asamblea'),
      'field_audiencia' => ['residente_titular', 'mesa_directiva', 'administracion'],
      'field_archivo' => $acta ? ['target_id' => $acta] : NULL,
    ]);

    $faqs = [
      '¿Cómo pre-registro una visita?' => 'En Seguridad y acceso → Registrar visita. Comparte el pase QR con tu visita.',
      '¿Cómo reservo el salón?' => 'En Instalaciones → Salón de fiestas → calendario y solicitud. Administración aprueba.',
      '¿Dónde veo las cuotas?' => 'En Comunidad → Cuotas y tarifas. Esta prueba de concepto no cobra en línea.',
      '¿Cómo reporto un foco fundido?' => 'Solicitudes → Nueva solicitud, categoría Alumbrado, con foto y ubicación.',
      '¿Las quejas pueden ser confidenciales?' => 'Sí. Marca la casilla; solo Administración verá tu identidad.',
    ];
    foreach ($faqs as $q => $a) {
      $this->node('faq', $q, ['body' => ['value' => '<p>' . $a . '</p>', 'format' => 'basic_html']]);
    }

    $this->node('directorio', 'Administración', [
      'field_telefono' => '+523387654321',
      'field_correo' => 'administracion@provenza.local',
      'body' => ['value' => '<p>Oficina de Administración, lunes a viernes 9:00–18:00.</p>', 'format' => 'basic_html'],
    ]);
    $this->node('directorio', 'Caseta', [
      'field_telefono' => '+523312345678',
      'body' => ['value' => '<p>Control de acceso 24 horas.</p>', 'format' => 'basic_html'],
    ]);
  }

  /**
   * Tracking records in several workflow states.
   */
  protected function tracking(array $houses, array $users): void {
    $ana = $users['ana.titular@provenza.local'] ?? NULL;
    $maria = $users['maria.inquilina@provenza.local'] ?? NULL;
    $maint = $users['mantenimiento@provenza.local'] ?? NULL;
    $house12 = $houses['Calle Provenza 12'] ?? NULL;
    $houseV = $houses['Av. Viñedos 3'] ?? NULL;
    $alumbrado = $this->tid('categorias_solicitud', 'Alumbrado');
    $alberca = $this->tid('categorias_solicitud', 'Alberca');
    $residuos = $this->tid('categorias_solicitud', 'Residuos');

    $this->node('solicitud', 'Foco fundido en Calle Provenza', [
      'uid' => $ana?->id() ?: 1,
      'field_domicilio' => $house12?->id(),
      'field_categoria_sol' => $alumbrado,
      'field_ubicacion' => 'Calle Provenza, frente al 12',
      'field_descripcion' => ['value' => '<p>El poste no enciende desde anoche.</p>', 'format' => 'basic_html'],
      'moderation_state' => 'recibida',
    ]);
    $this->node('solicitud', 'Bomba de la alberca hace ruido', [
      'uid' => $maria?->id() ?: 1,
      'field_domicilio' => $houseV?->id(),
      'field_categoria_sol' => $alberca,
      'field_ubicacion' => 'Cuarto de máquinas alberca',
      'field_descripcion' => ['value' => '<p>Ruido metálico al filtrar.</p>', 'format' => 'basic_html'],
      'field_asignado' => $maint?->id(),
      'moderation_state' => 'en_proceso',
    ]);
    $this->node('solicitud', 'Recolección especial de un sillón', [
      'uid' => $ana?->id() ?: 1,
      'field_domicilio' => $house12?->id(),
      'field_categoria_sol' => $residuos,
      'field_ubicacion' => 'Calle Provenza 12',
      'field_descripcion' => ['value' => '<p>Sillón de tres plazas.</p>', 'format' => 'basic_html'],
      'field_fecha_recoleccion' => date('Y-m-d', strtotime('+3 day')),
      'moderation_state' => 'resuelta',
      'field_calificacion' => 5,
      'field_comentario_calificacion' => 'Pasaron el día acordado.',
    ]);

    $this->node('queja', 'Música a altas horas', [
      'uid' => $ana?->id() ?: 1,
      'field_domicilio' => $house12?->id(),
      'field_tipo_queja' => $this->tid('tipos_queja', 'Ruido'),
      'field_confidencial' => 0,
      'field_descripcion' => ['value' => '<p>Fiesta hasta las 2:00.</p>', 'format' => 'basic_html'],
      'moderation_state' => 'en_investigacion',
    ]);
    $this->node('queja', 'Queja confidencial de trato en caseta', [
      'uid' => $maria?->id() ?: 1,
      'field_domicilio' => $houseV?->id(),
      'field_tipo_queja' => $this->tid('tipos_queja', 'Conducta del personal de seguridad'),
      'field_confidencial' => 1,
      'field_descripcion' => ['value' => '<p>Trato descortés al entrar.</p>', 'format' => 'basic_html'],
      'field_notas_internas' => ['value' => '<p>Solo Administración: se hablará con el turno noche.</p>', 'format' => 'basic_html'],
      'moderation_state' => 'recibida',
    ]);

    $salon = $this->nidByCode('salon');
    $this->node('reservacion', 'Cumpleaños en el salón', [
      'uid' => $ana?->id() ?: 1,
      'field_domicilio' => $house12?->id(),
      'field_instalacion' => $salon,
      'field_fecha_inicio' => gmdate('Y-m-d\TH:i:s', strtotime('+10 days 16:00')),
      'field_fecha_fin' => gmdate('Y-m-d\TH:i:s', strtotime('+10 days 22:00')),
      'field_invitados' => 40,
      'field_tipo_evento_txt' => 'Cumpleaños',
      'field_acepta_reglas' => 1,
      'moderation_state' => 'solicitada',
    ]);
    $this->node('reservacion', 'Reunión familiar (aprobada)', [
      'uid' => $maria?->id() ?: 1,
      'field_domicilio' => $houseV?->id(),
      'field_instalacion' => $salon,
      'field_fecha_inicio' => gmdate('Y-m-d\TH:i:s', strtotime('+20 days 12:00')),
      'field_fecha_fin' => gmdate('Y-m-d\TH:i:s', strtotime('+20 days 18:00')),
      'field_invitados' => 25,
      'field_tipo_evento_txt' => 'Reunión familiar',
      'field_acepta_reglas' => 1,
      'moderation_state' => 'aprobada',
    ]);

    $this->node('visita', 'Visita de Elena Ruiz', [
      'uid' => $ana?->id() ?: 1,
      'field_domicilio' => $house12?->id(),
      'field_nombre_visita' => 'Elena Ruiz',
      'field_ventana_inicio' => gmdate('Y-m-d\TH:i:s', strtotime('today 15:00')),
      'field_ventana_fin' => gmdate('Y-m-d\TH:i:s', strtotime('today 21:00')),
      'field_placa' => 'XYZ-9876',
      'field_num_personas' => 2,
      'field_tipo_autorizacion' => 'visita',
      'field_estado_visita' => 'programada',
    ]);
    $this->node('visita', 'Proveedor de gas', [
      'uid' => $maria?->id() ?: 1,
      'field_domicilio' => $houseV?->id(),
      'field_nombre_visita' => 'Gas Express',
      'field_ventana_inicio' => gmdate('Y-m-d\TH:i:s', strtotime('today 09:00')),
      'field_ventana_fin' => gmdate('Y-m-d\TH:i:s', strtotime('today 13:00')),
      'field_placa' => 'GAS-4421',
      'field_num_personas' => 1,
      'field_tipo_autorizacion' => 'proveedor',
      'field_estado_visita' => 'programada',
    ]);
    $this->node('visita', 'Personal doméstico (recurrente)', [
      'uid' => $ana?->id() ?: 1,
      'field_domicilio' => $house12?->id(),
      'field_nombre_visita' => 'Rosa Hernández',
      'field_ventana_inicio' => gmdate('Y-m-d\TH:i:s', strtotime('today 08:00')),
      'field_ventana_fin' => gmdate('Y-m-d\TH:i:s', strtotime('today 16:00')),
      'field_tipo_autorizacion' => 'domestico',
      'field_recurrente' => 1,
      'field_caducidad' => date('Y-m-d', strtotime('+6 months')),
      'field_estado_visita' => 'programada',
    ]);

    $this->node('incidente', 'Intento de ingreso sin pase', [
      'uid' => $users['caseta@provenza.local']->id() ?? 1,
      'field_fecha_incidente' => gmdate('Y-m-d\TH:i:s', strtotime('-1 day 22:10')),
      'field_ubicacion' => 'Caseta principal',
      'field_descripcion' => ['value' => '<p>Persona sin pre-registro insistió en entrar.</p>', 'format' => 'basic_html'],
    ]);
  }

  /**
   * Tutorial entries for every installed module.
   */
  protected function tutorial(): void {
    $modules = $this->moduleCatalog();
    foreach ($modules as $item) {
      $tid = $this->tid('categorias_modulo', $item['cat']);
      $photo = $this->placeholder('modulo-' . $item['id']);
      $fields = [
        'field_nombre_maquina' => $item['id'],
        'field_categoria_modulo' => $tid,
        'field_resumen' => $item['summary'],
        'field_donde_se_ve' => $item['where'],
        'field_pasos' => ['value' => $this->stepsHtml($item['steps']), 'format' => 'basic_html'],
        'field_detalle_tecnico' => ['value' => '<p>' . $item['tech'] . '</p>', 'format' => 'basic_html'],
        'field_version_modulo' => $item['version'],
        'field_enlace_proyecto' => ['uri' => $item['url'], 'title' => 'drupal.org'],
      ];
      if ($photo) {
        $fields['field_captura'] = ['target_id' => $photo, 'alt' => 'Captura de ' . $item['title'] . ' en el portal'];
      }
      $this->node('modulo_documentado', $item['title'], $fields);
    }
  }

  /**
   * Catalog used by the tutorial.
   */
  protected function moduleCatalog(): array {
    $v = fn (string $name) => $this->moduleVersion($name);
    return [
      ['id' => 'node', 'title' => 'Node', 'cat' => 'Contenido', 'summary' => 'Guarda cada contenido del sitio (avisos, solicitudes, instalaciones).', 'where' => 'Cualquier página de aviso, tarifa o ficha de esta guía.', 'steps' => ['Administración crea un tipo de contenido.', 'Se le agregan campos.', 'Los colonos o el personal publican nodos de ese tipo.'], 'tech' => 'Módulo del núcleo. Entidad node con bundles definidos en provenza_core.', 'version' => $v('node'), 'url' => 'https://www.drupal.org/project/drupal'],
      ['id' => 'user', 'title' => 'User', 'cat' => 'Seguridad', 'summary' => 'Cuentas, roles y permisos de las ocho figuras del condominio.', 'where' => 'Iniciar sesión, registrarme y Mi perfil.', 'steps' => ['El colono se registra con su número de casa.', 'Administración aprueba la cuenta.', 'El rol decide qué menús y expedientes ve.'], 'tech' => 'Roles personalizados + user fields (domicilio, vehículos, preferencias).', 'version' => $v('user'), 'url' => 'https://www.drupal.org/project/drupal'],
      ['id' => 'field', 'title' => 'Field / Field UI', 'cat' => 'Contenido', 'summary' => 'Añade datos extra a cada tipo de contenido, como folio, placa o monto.', 'where' => 'Formularios de solicitud y ficha de tarifa.', 'steps' => ['Se define un almacenamiento de campo.', 'Se adjunta al tipo de contenido.', 'Aparece en el formulario y en la página.'], 'tech' => 'Field API. Field UI permite editar sin código.', 'version' => $v('field'), 'url' => 'https://www.drupal.org/project/drupal'],
      ['id' => 'views', 'title' => 'Views', 'cat' => 'Contenido', 'summary' => 'Arma listas con filtros: tarifas, avisos, documentos, solicitudes.', 'where' => 'Cuotas y tarifas, avisos, preguntas frecuentes, documentos.', 'steps' => ['Se elige la fuente (nodos).', 'Se agregan campos y filtros.', 'Se publica como página.'], 'tech' => 'Vistas creadas en la instalación de provenza_core.', 'version' => $v('views'), 'url' => 'https://www.drupal.org/project/drupal'],
      ['id' => 'taxonomy', 'title' => 'Taxonomy', 'cat' => 'Contenido', 'summary' => 'Clasifica solicitudes, quejas, avisos y esta guía por categorías.', 'where' => 'Selector de categoría al crear una solicitud; tarjetas de módulos.', 'steps' => ['Se crea un vocabulario.', 'Se cargan términos (Alberca, Ruido, etc.).', 'Los contenidos se etiquetan con esos términos.'], 'tech' => 'Vocabularios categorias_solicitud, tipos_queja, categorias_modulo, etc.', 'version' => $v('taxonomy'), 'url' => 'https://www.drupal.org/project/drupal'],
      ['id' => 'menu_ui', 'title' => 'Menu UI', 'cat' => 'Administración', 'summary' => 'Permite editar los menús principal, de usuario, de pie, de personal y de caseta.', 'where' => 'Barra superior y pie de página.', 'steps' => ['Se define el menú.', 'Se agregan enlaces en español.', 'El pie incluye Así lo construimos y ningún otro menú lo hace.'], 'tech' => 'Menus main, footer, staff, guardhouse.', 'version' => $v('menu_ui'), 'url' => 'https://www.drupal.org/project/drupal'],
      ['id' => 'media', 'title' => 'Media / Media Library', 'cat' => 'Contenido', 'summary' => 'Repositorio de imágenes y archivos reutilizables.', 'where' => 'Fotos de instalaciones y biblioteca al editar contenidos.', 'steps' => ['Se sube un archivo a la biblioteca.', 'Se reutiliza en varios nodos.', 'Se pueden aplicar estilos de imagen.'], 'tech' => 'media + media_library del núcleo.', 'version' => $v('media'), 'url' => 'https://www.drupal.org/project/drupal'],
      ['id' => 'file', 'title' => 'File / Image / Responsive Image', 'cat' => 'Rendimiento', 'summary' => 'Sube fotos de evidencia y sirve tamaños distintos en el celular.', 'where' => 'Fotos de una solicitud o incidente; fotos de la alberca.', 'steps' => ['El colono adjunta hasta 5 fotos.', 'Se guardan en archivos privados si son evidencias.', 'El tema pide el tamaño adecuado a la pantalla.'], 'tech' => 'uri_scheme private para evidencias y documentos.', 'version' => $v('file'), 'url' => 'https://www.drupal.org/project/drupal'],
      ['id' => 'path', 'title' => 'Path', 'cat' => 'Contenido', 'summary' => 'Direcciones amigables como /instalaciones/alberca.', 'where' => 'La barra de direcciones de cualquier página pública.', 'steps' => ['Pathauto propone el alias.', 'Se puede ajustar a mano.', 'El visitante nunca ve node/123.'], 'tech' => 'path + pathauto.', 'version' => $v('path'), 'url' => 'https://www.drupal.org/project/drupal'],
      ['id' => 'locale', 'title' => 'Language / Locale / Translation', 'cat' => 'Idioma y accesibilidad', 'summary' => 'Pone la interfaz en español latinoamericano.', 'where' => 'Botones, fechas y mensajes de error.', 'steps' => ['El idioma por defecto es español.', 'Se importan traducciones de drupal.org.', 'La zona horaria es America/Mexico_City.'], 'tech' => 'locale es, country MX, formatos d/m/Y.', 'version' => $v('locale'), 'url' => 'https://www.drupal.org/project/drupal'],
      ['id' => 'content_moderation', 'title' => 'Workflows + Content Moderation', 'cat' => 'Automatización', 'summary' => 'Estados de solicitudes, quejas y reservaciones.', 'where' => 'El seguimiento de “Mis solicitudes” y la bandeja de Administración.', 'steps' => ['El expediente nace en Recibida o Solicitada.', 'Administración o mantenimiento avanzan el estado.', 'Cada cambio puede disparar un correo.'], 'tech' => 'Workflows solicitud_wf, queja_wf, reservacion_wf. Todos los estados published.', 'version' => $v('content_moderation'), 'url' => 'https://www.drupal.org/project/drupal'],
      ['id' => 'contact', 'title' => 'Contact', 'cat' => 'Formularios y solicitudes', 'summary' => 'Formulario general de contacto hacia Administración.', 'where' => 'Contacto en el menú y en el pie.', 'steps' => ['El visitante llena nombre, correo y mensaje.', 'Honeypot frena spam.', 'El correo llega a Administración (Mailpit en local).'], 'tech' => 'contact.form.feedback.', 'version' => $v('contact'), 'url' => 'https://www.drupal.org/project/drupal'],
      ['id' => 'block', 'title' => 'Block / Block Content', 'cat' => 'Contenido', 'summary' => 'Coloca piezas en regiones: avisos urgentes y acciones rápidas.', 'where' => 'Inicio y encabezado.', 'steps' => ['Se define un bloque.', 'Se coloca en una región del tema Olivero.', 'Se limita por rol o por página.'], 'tech' => 'Bloques provenza_quick_actions y provenza_urgent_notices.', 'version' => $v('block'), 'url' => 'https://www.drupal.org/project/drupal'],
      ['id' => 'layout_builder', 'title' => 'Layout Builder', 'cat' => 'Contenido', 'summary' => 'Permite armar el diseño de páginas e instalaciones sin código.', 'where' => 'Páginas básicas e instalaciones al editar el diseño.', 'steps' => ['Se activa en el tipo de contenido.', 'Administración añade secciones.', 'El público ve el resultado.'], 'tech' => 'Layout Builder en page e instalacion.', 'version' => $v('layout_builder'), 'url' => 'https://www.drupal.org/project/drupal'],
      ['id' => 'datetime', 'title' => 'Datetime / Datetime Range', 'cat' => 'Contenido', 'summary' => 'Campos de fecha para ventanas de visita y reservaciones.', 'where' => 'Calendario del salón y registro de visitas.', 'steps' => ['El colono elige fecha y hora.', 'El sistema compara con otras reservaciones.', 'Caseta filtra las visitas de hoy.'], 'tech' => 'datetime fields + Smart Date en eventos.', 'version' => $v('datetime'), 'url' => 'https://www.drupal.org/project/drupal'],
      ['id' => 'search', 'title' => 'Search', 'cat' => 'Contenido', 'summary' => 'Búsqueda básica de respaldo; el portal usa Search API hacia /buscar.', 'where' => 'Caja Buscar del encabezado.', 'steps' => ['Se escribe un término.', 'Se excluyen las fichas de esta guía.', 'Se respetan los permisos del rol.'], 'tech' => 'Core Search instalado; la UI pública usa la ruta personalizada /buscar.', 'version' => $v('search'), 'url' => 'https://www.drupal.org/project/drupal'],
      ['id' => 'dblog', 'title' => 'Database Logging', 'cat' => 'Administración', 'summary' => 'Registra errores técnicos y la bitácora de auditoría.', 'where' => 'Panel → Auditoría.', 'steps' => ['Un cambio de estado o de rol se registra.', 'Queda el usuario y la hora.', 'Administración puede revisar el historial.'], 'tech' => 'Canal watchdog auditoria.', 'version' => $v('dblog'), 'url' => 'https://www.drupal.org/project/drupal'],
      ['id' => 'automated_cron', 'title' => 'Automated Cron', 'cat' => 'Automatización', 'summary' => 'Ejecuta tareas programadas: avisos, caducidad de visitas, colas de correo.', 'where' => 'No se ve; trabaja en segundo plano.', 'steps' => ['Drupal dispara cron.', 'Se caducan autorizaciones vencidas.', 'Se envían correos en cola.'], 'tech' => 'hook_cron en provenza_core + queue provenza_announcement_mail.', 'version' => $v('automated_cron'), 'url' => 'https://www.drupal.org/project/drupal'],
      ['id' => 'update', 'title' => 'Update Manager', 'cat' => 'Administración', 'summary' => 'Avisa cuando hay parches de seguridad.', 'where' => 'Informes disponibles para webmaster.', 'steps' => ['El sitio consulta actualizaciones.', 'Webmaster ve el aviso.', 'Se aplica el parche con Composer.'], 'tech' => 'update module del núcleo.', 'version' => $v('update'), 'url' => 'https://www.drupal.org/project/drupal'],
      ['id' => 'page_cache', 'title' => 'Page Cache / Dynamic Page Cache / BigPipe', 'cat' => 'Rendimiento', 'summary' => 'Hace rápidas las páginas de visitantes y de colonos con sesión.', 'where' => 'Cualquier visita anónima a Inicio o tarifas.', 'steps' => ['Se genera la página.', 'Se guarda en caché.', 'La siguiente visita se sirve más rápido.'], 'tech' => 'page_cache, dynamic_page_cache, big_pipe.', 'version' => $v('page_cache'), 'url' => 'https://www.drupal.org/project/drupal'],
      ['id' => 'options', 'title' => 'Options / Text / Link / Telephone / Email', 'cat' => 'Contenido', 'summary' => 'Tipos de campo para teléfonos clicables, correos y listas.', 'where' => 'Teléfonos de emergencia (tel:) y directorio.', 'steps' => ['Se agrega un campo teléfono.', 'En el celular se vuelve un enlace para llamar.', 'El correo se abre en la app de mail.'], 'tech' => 'telephone_link formatter.', 'version' => $v('telephone'), 'url' => 'https://www.drupal.org/project/drupal'],
      ['id' => 'config', 'title' => 'Config', 'cat' => 'Administración', 'summary' => 'Guarda la configuración del sitio en código para moverla entre ambientes.', 'where' => 'No se ve; está en la carpeta config/sync.', 'steps' => ['Se exporta la configuración.', 'Se versiona en git.', 'Otro ambiente la importa.'], 'tech' => 'config_sync_directory = ../config/sync.', 'version' => $v('config'), 'url' => 'https://www.drupal.org/project/drupal'],
      ['id' => 'webform', 'title' => 'Webform', 'cat' => 'Formularios y solicitudes', 'summary' => 'Constructor de formularios de solicitudes, quejas, visitas y reservaciones.', 'where' => 'Nueva solicitud, nueva queja, registrar visita, reservar salón.', 'steps' => ['El colono llena el formulario.', 'Un handler crea el expediente con folio.', 'Queda el seguimiento en “Mis solicitudes”.'], 'tech' => 'Handler provenza_create_node.', 'version' => $v('webform'), 'url' => 'https://www.drupal.org/project/webform'],
      ['id' => 'eca', 'title' => 'ECA (Events, Conditions, Actions)', 'cat' => 'Automatización', 'summary' => 'Automatiza correos cuando un expediente cambia de estado, sin programar.', 'where' => 'Al pasar una reservación a Aprobada llega un correo (Mailpit en local).', 'steps' => ['Ocurre un evento de flujo de trabajo.', 'ECA evalúa condiciones.', 'Envía el correo y escribe en auditoría.'], 'tech' => 'Modelo provenza_status_mail + MailTemplateService.', 'version' => $v('eca'), 'url' => 'https://www.drupal.org/project/eca'],
      ['id' => 'smart_date', 'title' => 'Smart Date', 'cat' => 'Contenido', 'summary' => 'Fechas y recurrencias más cómodas para eventos y autorizaciones.', 'where' => 'Calendario de eventos comunitarios.', 'steps' => ['Se captura una fecha inteligente.', 'Puede repetirse (autorización recurrente).', 'Se muestra en el calendario.'], 'tech' => 'smart_date + smart_date_recur.', 'version' => $v('smart_date'), 'url' => 'https://www.drupal.org/project/smart_date'],
      ['id' => 'paragraphs', 'title' => 'Paragraphs', 'cat' => 'Contenido', 'summary' => 'Bloques flexibles: vehículos y mascotas en el perfil del colono.', 'where' => 'Mi perfil → vehículos y mascotas.', 'steps' => ['El titular agrega un párrafo Vehículo.', 'Captura placa, marca, color y tag.', 'Puede agregar varios.'], 'tech' => 'Paragraph types vehiculo y mascota.', 'version' => $v('paragraphs'), 'url' => 'https://www.drupal.org/project/paragraphs'],
      ['id' => 'pathauto', 'title' => 'Pathauto', 'cat' => 'Contenido', 'summary' => 'Genera alias en español de forma automática.', 'where' => '/comunidad/cuotas-y-tarifas y /asi-lo-construimos/modulos/...', 'steps' => ['Se define un patrón.', 'Al guardar el nodo se crea el alias.', 'Se evita duplicar a mano.'], 'tech' => 'PathautoPattern provenza_*.', 'version' => $v('pathauto'), 'url' => 'https://www.drupal.org/project/pathauto'],
      ['id' => 'token', 'title' => 'Token', 'cat' => 'Automatización', 'summary' => 'Marcadores como [node:folio] dentro de correos y alias.', 'where' => 'Plantillas de correo de Administración.', 'steps' => ['Administración edita el texto en español.', 'Inserta tokens.', 'Al enviar se reemplazan por el nombre y el folio reales.'], 'tech' => 'token + provenza_core_tokens.', 'version' => $v('token'), 'url' => 'https://www.drupal.org/project/token'],
      ['id' => 'metatag', 'title' => 'Metatag', 'cat' => 'Rendimiento', 'summary' => 'Títulos para buscadores y noindex de esta guía.', 'where' => 'Código fuente de las fichas de módulo (robots noindex).', 'steps' => ['Se define un metatag por tipo.', 'Las fichas de esta guía llevan noindex.', 'Las páginas públicas sí pueden indexarse.'], 'tech' => 'metatag.metatag_defaults.node__modulo_documentado.', 'version' => $v('metatag'), 'url' => 'https://www.drupal.org/project/metatag'],
      ['id' => 'redirect', 'title' => 'Redirect', 'cat' => 'Administración', 'summary' => 'Mantiene enlaces viejos cuando una URL cambia.', 'where' => 'No se ve; evita errores 404 al renombrar páginas.', 'steps' => ['Cambia el alias.', 'Redirect guarda el anterior.', 'Quien tenía el enlace llega igual.'], 'tech' => 'redirect module.', 'version' => $v('redirect'), 'url' => 'https://www.drupal.org/project/redirect'],
      ['id' => 'simple_sitemap', 'title' => 'Simple XML Sitemap', 'cat' => 'Rendimiento', 'summary' => 'Mapa XML de páginas públicas; excluye esta guía.', 'where' => '/sitemap.xml (sin Así lo construimos). El mapa HTML está en /mapa-del-sitio.', 'steps' => ['Se indexan páginas públicas.', 'Se excluye modulo_documentado.', 'Los buscadores leen el XML.'], 'tech' => 'Bundle modulo_documentado no indexado.', 'version' => $v('simple_sitemap'), 'url' => 'https://www.drupal.org/project/simple_sitemap'],
      ['id' => 'admin_toolbar', 'title' => 'Admin Toolbar', 'cat' => 'Administración', 'summary' => 'Barra de administración más rápida para el personal.', 'where' => 'Al iniciar sesión como Administración o webmaster.', 'steps' => ['El personal entra.', 'Ve un menú desplegable.', 'Llega más rápido a bandeja, residentes o configuración.'], 'tech' => 'admin_toolbar + admin_toolbar_tools.', 'version' => $v('admin_toolbar'), 'url' => 'https://www.drupal.org/project/admin_toolbar'],
      ['id' => 'gin', 'title' => 'Gin', 'cat' => 'Administración', 'summary' => 'Tema de administración moderno y accesible.', 'where' => 'Cualquier pantalla /admin.', 'steps' => ['Webmaster asigna Gin como tema admin.', 'El personal trabaja en formularios largos.', 'La interfaz es más clara que el tema Claro.'], 'tech' => 'Tema gin + gin_toolbar.', 'version' => $v('gin') ?: '5.x', 'url' => 'https://www.drupal.org/project/gin'],
      ['id' => 'field_group', 'title' => 'Field Group', 'cat' => 'Administración', 'summary' => 'Agrupa campos en pestañas o secciones en formularios largos.', 'where' => 'Edición de perfil y de expedientes en back office.', 'steps' => ['Se agrupan campos relacionados.', 'El formulario se vuelve más corto visualmente.', 'Se reduce el error de captura.'], 'tech' => 'field_group.', 'version' => $v('field_group'), 'url' => 'https://www.drupal.org/project/field_group'],
      ['id' => 'views_bulk_operations', 'title' => 'Views Bulk Operations', 'cat' => 'Administración', 'summary' => 'Acciones masivas: asignar o desactivar varios registros.', 'where' => 'Bandeja y acción de desactivar domicilio.', 'steps' => ['Se marcan varios elementos.', 'Se elige una acción.', 'Se aplican en lote.'], 'tech' => 'VBO + Action provenza_deactivate_house.', 'version' => $v('views_bulk_operations'), 'url' => 'https://www.drupal.org/project/views_bulk_operations'],
      ['id' => 'better_exposed_filters', 'title' => 'Better Exposed Filters', 'cat' => 'Administración', 'summary' => 'Filtros más claros en las listas.', 'where' => 'Filtros de la bandeja unificada y de las vistas.', 'steps' => ['Se exponen filtros.', 'El widget se vuelve más usable en móvil.', 'El personal acota por tipo o estado.'], 'tech' => 'better_exposed_filters.', 'version' => $v('better_exposed_filters'), 'url' => 'https://www.drupal.org/project/better_exposed_filters'],
      ['id' => 'views_data_export', 'title' => 'Views Data Export', 'cat' => 'Administración', 'summary' => 'Exporta listados a CSV para reportes de gestión.', 'where' => 'Bandeja unificada → Exportar CSV.', 'steps' => ['Se filtra la bandeja.', 'Se pulsa exportar.', 'Se descarga un CSV en español.'], 'tech' => 'También hay exportación propia en AdminController.', 'version' => $v('views_data_export'), 'url' => 'https://www.drupal.org/project/views_data_export'],
      ['id' => 'charts', 'title' => 'Charts', 'cat' => 'Administración', 'summary' => 'Gráficas a partir de datos de vistas (volumen, incidentes).', 'where' => 'Reportes y tableros del panel.', 'steps' => ['Una vista resume cifras.', 'Charts las dibuja.', 'Mesa directiva las consulta en solo lectura.'], 'tech' => 'charts + charts_chartjs. En el PoC los KPI se renderizan también en el panel.', 'version' => $v('charts'), 'url' => 'https://www.drupal.org/project/charts'],
      ['id' => 'entity_print', 'title' => 'Entity Print', 'cat' => 'Contenido', 'summary' => 'Genera PDF de actas y confirmaciones de reservación.', 'where' => 'En un documento o reservación, acción de imprimir/PDF.', 'steps' => ['Se abre el contenido.', 'Se elige PDF.', 'Dompdf genera el archivo.'], 'tech' => 'entity_print + dompdf/dompdf.', 'version' => $v('entity_print'), 'url' => 'https://www.drupal.org/project/entity_print'],
      ['id' => 'search_api', 'title' => 'Search API + Search API Database', 'cat' => 'Contenido', 'summary' => 'Índice de búsqueda avanzado sobre contenidos permitidos por rol.', 'where' => '/buscar. Esta guía no se indexa.', 'steps' => ['Se indexan nodos públicos.', 'Se excluye modulo_documentado.', 'La búsqueda respeta permisos.'], 'tech' => 'Server provenza_db, index contenido.', 'version' => $v('search_api'), 'url' => 'https://www.drupal.org/project/search_api'],
      ['id' => 'symfony_mailer', 'title' => 'Drupal Symfony Mailer', 'cat' => 'Automatización', 'summary' => 'Envío fiable de correos transaccionales.', 'where' => 'Bandeja de Mailpit en DDEV al cambiar un estado.', 'steps' => ['Un evento dispara la plantilla.', 'Symfony Mailer arma el mensaje.', 'En local lo captura Mailpit; en producción iría por SMTP autenticado.'], 'tech' => 'DDEV reescribe el transporte a localhost:1025.', 'version' => $v('symfony_mailer'), 'url' => 'https://www.drupal.org/project/symfony_mailer'],
      ['id' => 'honeypot', 'title' => 'Honeypot', 'cat' => 'Seguridad', 'summary' => 'Trampa antispam en registro y contacto, junto con reCAPTCHA v3.', 'where' => 'Formulario de registro, contacto y quejas (campo oculto + badge de reCAPTCHA).', 'steps' => ['Un bot llena el campo trampa o falla reCAPTCHA.', 'El envío se descarta.', 'Una persona no ve el campo oculto.'], 'tech' => 'honeypot en user_register_form y contact. reCAPTCHA v3 en los mismos formularios y en quejas.', 'version' => $v('honeypot'), 'url' => 'https://www.drupal.org/project/honeypot'],
      ['id' => 'recaptcha_v3', 'title' => 'reCAPTCHA v3', 'cat' => 'Seguridad', 'summary' => 'Puntuación invisible de Google contra spam en registro, contacto y quejas.', 'where' => 'Badge de reCAPTCHA al registrarse, escribir a Administración o presentar una queja.', 'steps' => ['La persona envía el formulario.', 'Google devuelve una puntuación.', 'Si es baja, se pide un reto adicional.'], 'tech' => 'captcha + recaptcha_v3. Acción provenza. En local, claves de prueba de Google.', 'version' => $v('recaptcha_v3') ?: $v('captcha'), 'url' => 'https://www.drupal.org/project/recaptcha_v3'],
      ['id' => 'tfa', 'title' => 'TFA (Two-factor Authentication)', 'cat' => 'Seguridad', 'summary' => 'Segundo factor TOTP obligatorio para Administración y webmaster.', 'where' => 'Al entrar con una cuenta de Administración se pide configurar la app de códigos.', 'steps' => ['El rol Administración o webmaster inicia sesión.', 'Debe vincular una app TOTP.', 'Los códigos de un solo uso protegen el back office.'], 'tech' => 'tfa + encrypt + real_aes + key. Roles required: administracion, webmaster.', 'version' => $v('tfa'), 'url' => 'https://www.drupal.org/project/tfa'],
      ['id' => 'password_policy', 'title' => 'Password Policy', 'cat' => 'Seguridad', 'summary' => 'Exige contraseñas de al menos 10 caracteres y 3 tipos, sin el nombre de usuario.', 'where' => 'Registro y cambio de contraseña.', 'steps' => ['La persona elige una contraseña.', 'La política la evalúa.', 'Si no cumple, se explica el motivo en español.'], 'tech' => 'password_policy provenza.', 'version' => $v('password_policy'), 'url' => 'https://www.drupal.org/project/password_policy'],
      ['id' => 'scheduler', 'title' => 'Scheduler', 'cat' => 'Automatización', 'summary' => 'Publica o retira avisos en una fecha programada.', 'where' => 'Al crear un aviso, campos de publicación programada.', 'steps' => ['Administración redacta el aviso.', 'Elige fecha de publicación.', 'Cron lo publica solo.'], 'tech' => 'scheduler en el tipo aviso.', 'version' => $v('scheduler'), 'url' => 'https://www.drupal.org/project/scheduler'],
      ['id' => 'flag', 'title' => 'Flag', 'cat' => 'Contenido', 'summary' => 'Permite guardar documentos o FAQs y marcar prioridad en la bandeja.', 'where' => 'Botón Guardar en documentos y FAQs; prioridad para el personal.', 'steps' => ['El colono marca Guardar.', 'El contenido queda en sus flags.', 'El personal marca prioridad en expedientes.'], 'tech' => 'Flags guardar y prioridad.', 'version' => $v('flag'), 'url' => 'https://www.drupal.org/project/flag'],
      ['id' => 'provenza_core', 'title' => 'Provenza Core (módulo propio)', 'cat' => 'Automatización', 'summary' => 'La lógica del condominio: folios, pases QR, permisos por casa, bandeja y correos.', 'where' => 'Inicio, caseta, panel, pases QR y esta guía.', 'steps' => ['Se instala el módulo.', 'Crea tipos, roles y formularios.', 'Los colonos ya pueden trabajar en el portal.'], 'tech' => 'Módulo custom web/modules/custom/provenza_core.', 'version' => '1.0.0', 'url' => 'https://www.drupal.org/project/drupal'],
      ['id' => 'provenza_demo', 'title' => 'Provenza Demo (módulo propio)', 'cat' => 'Administración', 'summary' => 'Carga casas, cuentas, tarifas de 200 a 500 MXN y expedientes de ejemplo.', 'where' => 'Las cuentas de demostración listadas en el README.', 'steps' => ['Se instala el módulo.', 'Crea datos de muestra.', 'Se puede volver a generar con drush provenza-demo:generate.'], 'tech' => 'DemoContentGenerator.', 'version' => '1.0.0', 'url' => 'https://www.drupal.org/project/drupal'],
    ];
  }

  protected function node(string $type, string $title, array $fields): Node {
    $nids = \Drupal::entityQuery('node')->accessCheck(FALSE)
      ->condition('type', $type)
      ->condition('title', $title)
      ->range(0, 1)
      ->execute();
    $node = $nids ? Node::load(reset($nids)) : Node::create(['type' => $type, 'title' => $title, 'status' => 1, 'uid' => $fields['uid'] ?? 1]);
    unset($fields['uid']);
    foreach ($fields as $name => $value) {
      if ($value === NULL || !$node->hasField($name)) {
        continue;
      }
      $node->set($name, $value);
    }
    $node->setTitle($title);
    $node->setPublished();
    $node->save();
    return $node;
  }

  protected function tid(string $vid, string $name): ?int {
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => $vid, 'name' => $name]);
    $term = reset($terms);
    return $term instanceof Term ? (int) $term->id() : NULL;
  }

  protected function nidByCode(string $code): ?int {
    $nids = \Drupal::entityQuery('node')->accessCheck(FALSE)
      ->condition('type', 'instalacion')
      ->condition('field_codigo_instalacion', $code)
      ->range(0, 1)
      ->execute();
    return $nids ? (int) reset($nids) : NULL;
  }

  protected function placeholder(string $slug): ?int {
    if (!function_exists('imagecreatetruecolor')) {
      return NULL;
    }
    $im = imagecreatetruecolor(1200, 675);
    $bg = imagecolorallocate($im, 31, 78, 70);
    $fg = imagecolorallocate($im, 246, 243, 238);
    imagefilledrectangle($im, 0, 0, 1200, 675, $bg);
    imagestring($im, 5, 40, 300, 'Provenza Residencial — ' . $slug, $fg);
    ob_start();
    imagepng($im);
    $data = ob_get_clean();
    imagedestroy($im);
    $dir = 'public://demo';
    \Drupal::service('file_system')->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $file = \Drupal::service('file.repository')->writeData($data, $dir . '/' . $slug . '.png', FileSystemInterface::EXISTS_REPLACE);
    return $file instanceof FileInterface ? (int) $file->id() : NULL;
  }

  protected function textFile(string $name, string $content): ?int {
    $dir = 'private://documentos';
    \Drupal::service('file_system')->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $file = \Drupal::service('file.repository')->writeData($content, $dir . '/' . $name, FileSystemInterface::EXISTS_REPLACE);
    if ($file instanceof FileInterface) {
      $file->setPermanent();
      $file->save();
      return (int) $file->id();
    }
    return NULL;
  }

  protected function stepsHtml(array $steps): string {
    $lis = '';
    foreach ($steps as $step) {
      $lis .= '<li>' . $step . '</li>';
    }
    return '<ol>' . $lis . '</ol>';
  }

  /**
   * Smart Date value for demo events (duration in minutes).
   */
  protected function smartDate(string $when, int $minutes): array {
    $start = strtotime($when);
    return [
      'value' => $start,
      'end_value' => $start + ($minutes * 60),
      'duration' => $minutes,
    ];
  }

  protected function moduleVersion(string $name): string {
    $list = \Drupal::service('extension.list.module')->getAllInstalledInfo();
    return $list[$name]['version'] ?? '11';
  }

}
