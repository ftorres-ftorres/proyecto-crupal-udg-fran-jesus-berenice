<?php

namespace Drupal\provenza_core\Install;

use Drupal\block\Entity\Block;
use Drupal\contact\Entity\ContactForm;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Language\LanguageInterface;
use Drupal\encrypt\Entity\EncryptionProfile;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\flag\Entity\Flag;
use Drupal\key\Entity\Key;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\pathauto\Entity\PathautoPattern;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use Drupal\views\Entity\View;
use Drupal\webform\Entity\Webform;
use Drupal\webform\WebformInterface;
use Drupal\workflows\Entity\Workflow;
use Drupal\eca\Entity\Eca;
use Drupal\captcha\Entity\CaptchaPoint;
use Drupal\recaptcha_v3\Entity\ReCaptchaV3Action;

/**
 * Creates the Provenza site structure on module install.
 */
class SiteInstaller {

  /**
   * Runs all install steps.
   */
  public function install(): void {
    $this->configureSite();
    $this->createRoles();
    $this->createVocabularies();
    $this->createParagraphTypes();
    $this->createNodeTypes();
    $this->createUserFields();
    $this->createWorkflows();
    $this->createWebforms();
    $this->createMenus();
    $this->safe('pathauto', fn () => $this->createPathauto());
    $this->safe('flags', fn () => $this->createFlags());
    $this->safe('search', fn () => $this->createSearch());
    $this->safe('security', fn () => $this->createSecurity());
    $this->safe('views', fn () => $this->createViews());
    $this->createPages();
    $this->safe('blocks', fn () => $this->placeBlocks());
    $this->createEcaModels();
    $this->grantPermissions();
    $this->safe('requisitos', fn () => $this->applyRequirementsFixes());
    node_access_rebuild(TRUE);
  }

  /**
   * Runs a step without aborting the whole install.
   */
  protected function safe(string $step, callable $callback): void {
    try {
      $callback();
    }
    catch (\Throwable $e) {
      \Drupal::logger('provenza_core')->error('Paso @step: @m', ['@step' => $step, '@m' => $e->getMessage()]);
    }
  }

  /**
   * Regional and site settings.
   */
  protected function configureSite(): void {
    $site = \Drupal::configFactory()->getEditable('system.site');
    $site->set('name', 'Portal de colonos · Provenza Residencial');
    $site->set('slogan', 'Colonos del Condominio Residencial AC');
    $site->set('page.front', '/inicio');
    $site->set('mail', 'no-reply@provenza.ddev.site')->save();

    \Drupal::configFactory()->getEditable('system.date')
      ->set('country.default', 'MX')
      ->set('timezone.default', 'America/Mexico_City')
      ->set('first_day', 1)
      ->save();

    \Drupal::configFactory()->getEditable('system.theme')
      ->set('default', 'olivero')
      ->set('admin', 'gin')
      ->save();

    \Drupal::configFactory()->getEditable('user.settings')
      ->set('register', 'visitors_administrative_approval')
      ->set('verify_mail', TRUE)
      ->save();

    \Drupal::configFactory()->getEditable('user.flood')
      ->set('ip_limit', 5)
      ->set('user_limit', 5)
      ->save();

    \Drupal::configFactory()->getEditable('honeypot.settings')
      ->set('form_settings.user_register_form', TRUE)
      ->set('form_settings.contact_message_feedback_form', TRUE)
      ->set('time_limit', 5)
      ->save();

    \Drupal::configFactory()->getEditable('metatag.settings')
      ->set('entity_type_groups', [])
      ->save();

    if ($contact = ContactForm::load('feedback')) {
      $contact->setRecipients(['administracion@provenza.local']);
      $contact->setRedirectPath('/contacto');
      $contact->setMessage('Gracias. Tu mensaje fue enviado a Administración.');
      $contact->save();
    }

    \Drupal::configFactory()->getEditable('simple_sitemap.settings')
      ->set('cron_generate', TRUE)
      ->save();

    if (\Drupal::configFactory()->getEditable('entity_print.settings')) {
      \Drupal::configFactory()->getEditable('entity_print.settings')
        ->set('print_engines.pdf_engine', 'dompdf__dompdf')
        ->save();
    }

    \Drupal::configFactory()->getEditable('gin.settings')
      ->set('preset_accent_color', 'blue')
      ->set('classic_toolbar', 'horizontal')
      ->set('enable_darkmode', 0)
      ->save();

    \Drupal::configFactory()->getEditable('charts.settings')
      ->set('charts_default_settings.library', 'chartjs')
      ->set('charts_default_settings.type', 'column')
      ->save();

    \Drupal::configFactory()->getEditable('webform.settings')
      ->set('file.file_private_redirect_message', 'Inicia sesión para ver el archivo adjunto.')
      ->set('settings.default_confirmation_back_label', 'Volver al formulario')
      ->save();
  }

  /**
   * Creates the eight roles from the brief.
   */
  protected function createRoles(): void {
    $roles = [
      'residente_titular' => 'Residente titular',
      'residente_familiar' => 'Residente familiar / inquilino',
      'caseta' => 'Caseta / Seguridad',
      'mantenimiento' => 'Mantenimiento',
      'administracion' => 'Administración',
      'mesa_directiva' => 'Mesa directiva',
      'webmaster' => 'Webmaster',
    ];
    foreach ($roles as $id => $label) {
      if (!Role::load($id)) {
        Role::create(['id' => $id, 'label' => $label])->save();
      }
    }
  }

  /**
   * Vocabularies and terms.
   */
  protected function createVocabularies(): void {
    $vocabs = [
      'categorias_solicitud' => [
        'label' => 'Categorías de solicitud',
        'terms' => [
          'Alumbrado', 'Jardines', 'Fuentes', 'Trotapista', 'Juegos infantiles',
          'Gimnasio', 'Alberca', 'Residuos', 'Reparación de áreas comunes',
          'Tag de acceso', 'Otro',
        ],
      ],
      'tipos_queja' => [
        'label' => 'Tipos de queja o sugerencia',
        'terms' => [
          'Ruido', 'Conflicto entre vecinos', 'Mascotas', 'Estacionamiento',
          'Conducta del personal de seguridad', 'Calidad del servicio',
          'Incumplimiento de reglamento', 'Otro',
        ],
      ],
      'categorias_aviso' => [
        'label' => 'Categorías de aviso',
        'terms' => ['General', 'Seguridad', 'Mantenimiento', 'Eventos'],
      ],
      'tipos_evento' => [
        'label' => 'Tipos de evento',
        'terms' => [
          'Asamblea', 'Corte de agua', 'Corte de luz', 'Jornada de jardinería',
          'Fumigación', 'Recolección de residuos', 'Social',
        ],
      ],
      'tipos_documento' => [
        'label' => 'Tipos de documento',
        'terms' => ['Reglamento', 'Acta de asamblea', 'Formulario', 'Otro'],
      ],
      'categorias_modulo' => [
        'label' => 'Categorías de módulo',
        'terms' => [
          'Contenido', 'Formularios y solicitudes', 'Automatización',
          'Seguridad', 'Rendimiento', 'Administración', 'Idioma y accesibilidad',
        ],
      ],
    ];
    foreach ($vocabs as $vid => $info) {
      if (!Vocabulary::load($vid)) {
        Vocabulary::create(['vid' => $vid, 'name' => $info['label']])->save();
      }
      foreach ($info['terms'] as $name) {
        $existing = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties([
          'vid' => $vid,
          'name' => $name,
        ]);
        if (!$existing) {
          Term::create(['vid' => $vid, 'name' => $name])->save();
        }
      }
    }
  }

  /**
   * Paragraph types for vehicles and pets.
   */
  protected function createParagraphTypes(): void {
    $this->ensureParagraphType('vehiculo', 'Vehículo', [
      ['field_placa', 'string', 'Placa'],
      ['field_marca', 'string', 'Marca'],
      ['field_color', 'string', 'Color'],
      ['field_tag_acceso', 'string', 'Tag de acceso'],
    ]);
    $this->ensureParagraphType('mascota', 'Mascota', [
      ['field_nombre_mascota', 'string', 'Nombre'],
      ['field_tipo_mascota', 'string', 'Tipo'],
      ['field_notas_mascota', 'string', 'Notas'],
    ]);
  }

  /**
   * Node types and their fields.
   */
  protected function createNodeTypes(): void {
    if (!FieldStorageConfig::loadByName('node', 'body')) {
      FieldStorageConfig::create([
        'field_name' => 'body',
        'entity_type' => 'node',
        'type' => 'text_with_summary',
        'cardinality' => 1,
      ])->save();
    }

    $types = [
      'page' => 'Página',
      'domicilio' => 'Domicilio',
      'solicitud' => 'Solicitud de servicio',
      'queja' => 'Queja o sugerencia',
      'reservacion' => 'Reservación',
      'visita' => 'Visita / Autorización',
      'incidente' => 'Incidente',
      'aviso' => 'Aviso',
      'evento' => 'Evento',
      'documento' => 'Documento',
      'instalacion' => 'Instalación',
      'tarifa' => 'Tarifa',
      'faq' => 'Pregunta frecuente',
      'directorio' => 'Directorio',
      'modulo_documentado' => 'Módulo documentado',
    ];
    $with_body = [
      'aviso', 'evento', 'faq', 'instalacion', 'page', 'documento',
      'modulo_documentado', 'directorio', 'queja', 'solicitud', 'incidente',
    ];
    foreach ($types as $id => $label) {
      if (!NodeType::load($id)) {
        $type = NodeType::create([
          'type' => $id,
          'name' => $label,
          'new_revision' => TRUE,
          'display_submitted' => FALSE,
          'preview_mode' => 0,
        ]);
        $type->save();
      }
      if (in_array($id, $with_body, TRUE)) {
        $this->ensureInstance('node', $id, 'body', 'Contenido');
      }
    }

    // Enable Layout Builder on facility pages. Hub pages use the body field.
    foreach (['instalacion'] as $bundle) {
      $display = EntityViewDisplay::load("node.$bundle.default");
      if (!$display) {
        $display = EntityViewDisplay::create([
          'targetEntityType' => 'node',
          'bundle' => $bundle,
          'mode' => 'default',
          'status' => TRUE,
        ]);
      }
      $display->setThirdPartySetting('layout_builder', 'enabled', TRUE);
      $display->setThirdPartySetting('layout_builder', 'allow_custom', TRUE);
      $display->save();
    }

    // Enable scheduler on avisos.
    if ($type = NodeType::load('aviso')) {
      $type->setThirdPartySetting('scheduler', 'publish_enable', TRUE);
      $type->setThirdPartySetting('scheduler', 'unpublish_enable', TRUE);
      $type->save();
    }

    $this->createSharedStorages();
    $this->attachNodeFields();
    $this->createDisplays();
  }

  /**
   * Field storages reused across bundles.
   */
  protected function createSharedStorages(): void {
    $storages = [
      ['node', 'field_folio', 'string', 1, []],
      ['node', 'field_domicilio', 'entity_reference', 1, ['target_type' => 'node']],
      ['node', 'field_fotos', 'image', 5, ['target_type' => 'file', 'uri_scheme' => 'private']],
      ['node', 'field_descripcion', 'text_long', 1, []],
      ['node', 'field_ubicacion', 'string', 1, []],
      ['node', 'field_asignado', 'entity_reference', 1, ['target_type' => 'user']],
      ['node', 'field_calificacion', 'integer', 1, []],
      ['node', 'field_comentario_calificacion', 'string_long', 1, []],
      ['node', 'field_fecha_recoleccion', 'datetime', 1, ['datetime_type' => 'date']],
      ['node', 'field_categoria_sol', 'entity_reference', 1, ['target_type' => 'taxonomy_term']],
      ['node', 'field_tipo_queja', 'entity_reference', 1, ['target_type' => 'taxonomy_term']],
      ['node', 'field_confidencial', 'boolean', 1, []],
      ['node', 'field_notas_internas', 'text_long', 1, []],
      ['node', 'field_mensaje_resolucion', 'text_long', 1, []],
      ['node', 'field_instalacion', 'entity_reference', 1, ['target_type' => 'node']],
      ['node', 'field_fecha_inicio', 'datetime', 1, ['datetime_type' => 'datetime']],
      ['node', 'field_fecha_fin', 'datetime', 1, ['datetime_type' => 'datetime']],
      ['node', 'field_invitados', 'integer', 1, []],
      ['node', 'field_tipo_evento_txt', 'string', 1, []],
      ['node', 'field_acepta_reglas', 'boolean', 1, []],
      ['node', 'field_nombre_visita', 'string', 1, []],
      ['node', 'field_ventana_inicio', 'datetime', 1, ['datetime_type' => 'datetime']],
      ['node', 'field_ventana_fin', 'datetime', 1, ['datetime_type' => 'datetime']],
      ['node', 'field_placa', 'string', 1, []],
      ['node', 'field_num_personas', 'integer', 1, []],
      ['node', 'field_codigo_qr', 'string', 1, []],
      ['node', 'field_qr', 'image', 1, ['target_type' => 'file', 'uri_scheme' => 'public']],
      ['node', 'field_tipo_autorizacion', 'list_string', 1, ['allowed_values' => [
        'visita' => 'Visita',
        'proveedor' => 'Proveedor',
        'domestico' => 'Personal doméstico',
        'cuidador' => 'Cuidador',
        'recurrente' => 'Autorización recurrente',
      ]]],
      ['node', 'field_caducidad', 'datetime', 1, ['datetime_type' => 'date']],
      ['node', 'field_checkin', 'datetime', 1, ['datetime_type' => 'datetime']],
      ['node', 'field_checkout', 'datetime', 1, ['datetime_type' => 'datetime']],
      ['node', 'field_estado_visita', 'list_string', 1, ['allowed_values' => [
        'programada' => 'Programada',
        'en_caseta' => 'En caseta / dentro',
        'salio' => 'Salió',
        'vencida' => 'Vencida',
        'cancelada' => 'Cancelada',
      ]]],
      ['node', 'field_recurrente', 'boolean', 1, []],
      ['node', 'field_fecha_incidente', 'datetime', 1, ['datetime_type' => 'datetime']],
      ['node', 'field_categoria_aviso', 'entity_reference', 1, ['target_type' => 'taxonomy_term']],
      ['node', 'field_enviar_correo', 'boolean', 1, []],
      ['node', 'field_urgente', 'boolean', 1, []],
      ['node', 'field_tipo_evento', 'entity_reference', 1, ['target_type' => 'taxonomy_term']],
      ['node', 'field_fecha_evento', 'smartdate', 1, []],
      ['node', 'field_archivo', 'file', 1, ['target_type' => 'file', 'uri_scheme' => 'private']],
      ['node', 'field_tipo_documento', 'entity_reference', 1, ['target_type' => 'taxonomy_term']],
      ['node', 'field_audiencia', 'list_string', -1, ['allowed_values' => [
        'visitante' => 'Público / visitante',
        'residente_titular' => 'Residente titular',
        'residente_familiar' => 'Residente familiar / inquilino',
        'caseta' => 'Caseta',
        'mantenimiento' => 'Mantenimiento',
        'administracion' => 'Administración',
        'mesa_directiva' => 'Mesa directiva',
      ]]],
      ['node', 'field_fecha_documento', 'datetime', 1, ['datetime_type' => 'date']],
      ['node', 'field_reglas', 'text_long', 1, []],
      ['node', 'field_horarios', 'string_long', 1, []],
      ['node', 'field_capacidad', 'integer', 1, []],
      ['node', 'field_codigo_instalacion', 'list_string', 1, ['allowed_values' => [
        'salon' => 'Salón de fiestas',
        'gym' => 'Gimnasio',
        'alberca' => 'Alberca',
        'trotapista' => 'Trotapista y áreas verdes',
        'juegos' => 'Juegos infantiles',
        'fuentes' => 'Fuentes',
        'jardines' => 'Jardines',
      ]]],
      ['node', 'field_tarifa', 'entity_reference', -1, ['target_type' => 'node']],
      ['node', 'field_fotos_publicas', 'image', 8, ['target_type' => 'file', 'uri_scheme' => 'public']],
      ['node', 'field_concepto', 'string', 1, []],
      ['node', 'field_monto', 'decimal', 1, ['precision' => 10, 'scale' => 2]],
      ['node', 'field_codigo_tarifa', 'string', 1, []],
      ['node', 'field_telefono', 'telephone', 1, []],
      ['node', 'field_correo', 'email', 1, []],
      ['node', 'field_calle', 'string', 1, []],
      ['node', 'field_numero', 'string', 1, []],
      ['node', 'field_titular', 'entity_reference', 1, ['target_type' => 'user']],
      ['node', 'field_estado_domicilio', 'list_string', 1, ['allowed_values' => [
        'activo' => 'Activo',
        'cambio_titular' => 'Cambio de titular',
        'desactivado' => 'Desactivado',
      ]]],
      ['node', 'field_categoria_modulo', 'entity_reference', 1, ['target_type' => 'taxonomy_term']],
      ['node', 'field_resumen', 'string_long', 1, []],
      ['node', 'field_donde_se_ve', 'string_long', 1, []],
      ['node', 'field_pasos', 'text_long', 1, []],
      ['node', 'field_detalle_tecnico', 'text_long', 1, []],
      ['node', 'field_version_modulo', 'string', 1, []],
      ['node', 'field_enlace_proyecto', 'link', 1, []],
      ['node', 'field_captura', 'image', 1, ['target_type' => 'file', 'uri_scheme' => 'public']],
      ['node', 'field_nombre_maquina', 'string', 1, []],
      ['user', 'field_nombre_completo', 'string', 1, []],
      ['user', 'field_telefono', 'telephone', 1, []],
      ['user', 'field_contacto_emergencia_nom', 'string', 1, []],
      ['user', 'field_contacto_emergencia_tel', 'telephone', 1, []],
      ['user', 'field_domicilio', 'entity_reference', 1, ['target_type' => 'node']],
      ['user', 'field_relacion', 'list_string', 1, ['allowed_values' => [
        'titular' => 'Titular',
        'familiar' => 'Familiar',
        'inquilino' => 'Inquilino',
      ]]],
      ['user', 'field_vehiculos', 'entity_reference_revisions', -1, ['target_type' => 'paragraph']],
      ['user', 'field_mascotas', 'entity_reference_revisions', -1, ['target_type' => 'paragraph']],
      ['user', 'field_notif_solicitudes', 'boolean', 1, []],
      ['user', 'field_notif_avisos', 'boolean', 1, []],
      ['user', 'field_notif_reservaciones', 'boolean', 1, []],
      ['user', 'field_numero_casa', 'string', 1, []],
      ['paragraph', 'field_placa', 'string', 1, []],
      ['paragraph', 'field_marca', 'string', 1, []],
      ['paragraph', 'field_color', 'string', 1, []],
      ['paragraph', 'field_tag_acceso', 'string', 1, []],
      ['paragraph', 'field_nombre_mascota', 'string', 1, []],
      ['paragraph', 'field_tipo_mascota', 'string', 1, []],
      ['paragraph', 'field_notas_mascota', 'string', 1, []],
    ];
    foreach ($storages as [$entity, $name, $type, $card, $settings]) {
      $this->ensureStorage($entity, $name, $type, $card, $settings);
    }
  }

  /**
   * Attaches fields to node bundles.
   */
  protected function attachNodeFields(): void {
    $map = [
      'domicilio' => [
        ['field_calle', 'Calle', TRUE],
        ['field_numero', 'Número', TRUE],
        ['field_titular', 'Titular'],
        ['field_estado_domicilio', 'Estado'],
      ],
      'solicitud' => [
        ['field_folio', 'Folio'],
        ['field_categoria_sol', 'Categoría', TRUE],
        ['field_ubicacion', 'Ubicación', TRUE],
        ['field_descripcion', 'Descripción', TRUE],
        ['field_fotos', 'Fotos'],
        ['field_asignado', 'Asignado a'],
        ['field_domicilio', 'Domicilio'],
        ['field_calificacion', 'Calificación'],
        ['field_comentario_calificacion', 'Comentario de calificación'],
        ['field_fecha_recoleccion', 'Fecha de recolección especial'],
      ],
      'queja' => [
        ['field_folio', 'Folio'],
        ['field_tipo_queja', 'Tipo', TRUE],
        ['field_confidencial', 'Confidencial'],
        ['field_descripcion', 'Descripción', TRUE],
        ['field_fotos', 'Fotos'],
        ['field_notas_internas', 'Notas internas'],
        ['field_mensaje_resolucion', 'Mensaje de resolución'],
        ['field_domicilio', 'Domicilio'],
      ],
      'reservacion' => [
        ['field_folio', 'Folio'],
        ['field_instalacion', 'Instalación', TRUE],
        ['field_fecha_inicio', 'Inicio', TRUE],
        ['field_fecha_fin', 'Fin', TRUE],
        ['field_invitados', 'Número de invitados'],
        ['field_tipo_evento_txt', 'Tipo de evento'],
        ['field_acepta_reglas', 'Acepta el reglamento'],
        ['field_domicilio', 'Domicilio'],
      ],
      'visita' => [
        ['field_folio', 'Folio'],
        ['field_nombre_visita', 'Nombre de la visita', TRUE],
        ['field_ventana_inicio', 'Inicio de la ventana', TRUE],
        ['field_ventana_fin', 'Fin de la ventana', TRUE],
        ['field_placa', 'Placa'],
        ['field_num_personas', 'Número de personas'],
        ['field_codigo_qr', 'Código QR'],
        ['field_qr', 'Pase QR'],
        ['field_tipo_autorizacion', 'Tipo de autorización'],
        ['field_caducidad', 'Caducidad'],
        ['field_checkin', 'Entrada'],
        ['field_checkout', 'Salida'],
        ['field_estado_visita', 'Estado'],
        ['field_recurrente', 'Recurrente'],
        ['field_domicilio', 'Domicilio'],
      ],
      'incidente' => [
        ['field_folio', 'Folio'],
        ['field_fecha_incidente', 'Fecha y hora', TRUE],
        ['field_ubicacion', 'Lugar', TRUE],
        ['field_descripcion', 'Descripción', TRUE],
        ['field_fotos', 'Fotos'],
        ['field_domicilio', 'Domicilio'],
      ],
      'aviso' => [
        ['field_categoria_aviso', 'Categoría'],
        ['field_urgente', 'Urgente'],
        ['field_enviar_correo', 'Enviar por correo a residentes'],
      ],
      'evento' => [
        ['field_tipo_evento', 'Tipo'],
        ['field_fecha_evento', 'Fecha'],
        ['field_ubicacion', 'Lugar'],
      ],
      'documento' => [
        ['field_archivo', 'Archivo', TRUE],
        ['field_tipo_documento', 'Tipo'],
        ['field_audiencia', 'Audiencia'],
        ['field_fecha_documento', 'Fecha'],
      ],
      'instalacion' => [
        ['field_codigo_instalacion', 'Código'],
        ['field_reglas', 'Reglamento de uso'],
        ['field_horarios', 'Horarios'],
        ['field_capacidad', 'Capacidad'],
        ['field_fotos_publicas', 'Fotos'],
        ['field_tarifa', 'Tarifas relacionadas'],
      ],
      'tarifa' => [
        ['field_concepto', 'Concepto', TRUE],
        ['field_monto', 'Monto (MXN)', TRUE],
        ['field_codigo_tarifa', 'Código'],
      ],
      'directorio' => [
        ['field_telefono', 'Teléfono'],
        ['field_correo', 'Correo'],
      ],
      'modulo_documentado' => [
        ['field_nombre_maquina', 'Nombre de máquina'],
        ['field_categoria_modulo', 'Categoría', TRUE],
        ['field_resumen', 'Qué hace'],
        ['field_donde_se_ve', 'Dónde se ve en este sitio'],
        ['field_pasos', 'Cómo funciona'],
        ['field_detalle_tecnico', 'Detalle técnico'],
        ['field_version_modulo', 'Versión'],
        ['field_enlace_proyecto', 'Página del proyecto'],
        ['field_captura', 'Captura'],
      ],
    ];

    $handler_settings = [
      'field_domicilio' => ['handler' => 'default:node', 'handler_settings' => ['target_bundles' => ['domicilio' => 'domicilio']]],
      'field_instalacion' => ['handler' => 'default:node', 'handler_settings' => ['target_bundles' => ['instalacion' => 'instalacion']]],
      'field_tarifa' => ['handler' => 'default:node', 'handler_settings' => ['target_bundles' => ['tarifa' => 'tarifa']]],
      'field_categoria_sol' => ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['categorias_solicitud' => 'categorias_solicitud']]],
      'field_tipo_queja' => ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['tipos_queja' => 'tipos_queja']]],
      'field_categoria_aviso' => ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['categorias_aviso' => 'categorias_aviso']]],
      'field_tipo_evento' => ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['tipos_evento' => 'tipos_evento']]],
      'field_tipo_documento' => ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['tipos_documento' => 'tipos_documento']]],
      'field_categoria_modulo' => ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['categorias_modulo' => 'categorias_modulo']]],
      'field_titular' => ['handler' => 'default:user', 'handler_settings' => []],
      'field_asignado' => ['handler' => 'default:user', 'handler_settings' => []],
    ];

    foreach ($map as $bundle => $fields) {
      foreach ($fields as $field) {
        $name = $field[0];
        $label = $field[1];
        $required = $field[2] ?? FALSE;
        $settings = $handler_settings[$name] ?? [];
        $this->ensureInstance('node', $bundle, $name, $label, $required, $settings);
      }
    }
  }

  /**
   * User profile fields.
   */
  protected function createUserFields(): void {
    $fields = [
      ['field_nombre_completo', 'Nombre completo', TRUE],
      ['field_telefono', 'Teléfono', TRUE],
      ['field_contacto_emergencia_nom', 'Contacto de emergencia'],
      ['field_contacto_emergencia_tel', 'Teléfono de emergencia'],
      ['field_domicilio', 'Domicilio'],
      ['field_relacion', 'Relación con el domicilio'],
      ['field_vehiculos', 'Vehículos'],
      ['field_mascotas', 'Mascotas'],
      ['field_notif_solicitudes', 'Avisarme de mis solicitudes'],
      ['field_notif_avisos', 'Avisarme de avisos comunitarios'],
      ['field_notif_reservaciones', 'Avisarme de reservaciones'],
      ['field_numero_casa', 'Número de casa (al registrarse)'],
    ];
    $settings = [
      'field_domicilio' => ['handler' => 'default:node', 'handler_settings' => ['target_bundles' => ['domicilio' => 'domicilio']]],
      'field_vehiculos' => ['handler' => 'default:paragraph', 'handler_settings' => ['target_bundles' => ['vehiculo' => 'vehiculo']]],
      'field_mascotas' => ['handler' => 'default:paragraph', 'handler_settings' => ['target_bundles' => ['mascota' => 'mascota']]],
    ];
    foreach ($fields as $field) {
      $this->ensureInstance('user', 'user', $field[0], $field[1], $field[2] ?? FALSE, $settings[$field[0]] ?? []);
    }

    $form = $this->ensureFormDisplay('user', 'user');
    $this->configureUserForm($form);
    $form->save();
    $register = EntityFormDisplay::load('user.user.register');
    if (!$register) {
      $register = EntityFormDisplay::create([
        'targetEntityType' => 'user',
        'bundle' => 'user',
        'mode' => 'register',
        'status' => TRUE,
      ]);
    }
    foreach (['field_nombre_completo', 'field_telefono', 'field_numero_casa'] as $i => $name) {
      $register->setComponent($name, ['type' => $name === 'field_telefono' ? 'telephone_default' : 'string_textfield', 'weight' => $i]);
    }
    foreach (['language', 'path', 'timezone', 'contact'] as $name) {
      $register->removeComponent($name);
    }
    $register->save();
  }

  /**
   * Form and view displays for node types.
   */
  protected function createDisplays(): void {
    $types = [
      'domicilio', 'solicitud', 'queja', 'reservacion', 'visita', 'incidente',
      'aviso', 'evento', 'documento', 'instalacion', 'tarifa', 'faq',
      'directorio', 'modulo_documentado',
    ];
    foreach ($types as $bundle) {
      $form = $this->ensureFormDisplay('node', $bundle);
      $view = $this->ensureViewDisplay('node', $bundle);
      $fields = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', $bundle);
      $w = 0;
      foreach ($fields as $name => $definition) {
        if (!$definition->isDisplayConfigurable('form') || str_starts_with($name, 'revision_') || in_array($name, ['created', 'changed', 'promote', 'sticky', 'uid', 'status'], TRUE)) {
          continue;
        }
        $widget = $this->defaultWidget($definition->getType());
        $form->setComponent($name, ['type' => $widget, 'weight' => $w]);
        if (in_array($name, ['langcode', 'path', 'publish_on', 'unpublish_on'], TRUE)) {
          $view->removeComponent($name);
          $w++;
          continue;
        }
        $settings = [];
        if ($name === 'field_monto') {
          $settings = [
            'thousand_separator' => ',',
            'decimal_separator' => '.',
            'scale' => 0,
            'prefix_suffix' => TRUE,
          ];
        }
        $view->setComponent($name, [
          'type' => $this->defaultFormatter($definition->getType()),
          'weight' => $w,
          'label' => 'above',
          'settings' => $settings,
        ]);
        $w++;
      }
      $form->save();
      $view->save();
    }
  }

  /**
   * Content moderation workflows.
   */
  protected function createWorkflows(): void {
    $this->ensureWorkflow('solicitud_wf', 'Solicitudes de servicio', [
      'recibida' => 'Recibida',
      'en_revision' => 'En revisión',
      'asignada' => 'Asignada',
      'en_proceso' => 'En proceso',
      'resuelta' => 'Resuelta',
      'cerrada' => 'Cerrada',
      'rechazada' => 'Rechazada',
    ], 'recibida', ['solicitud']);

    $this->ensureWorkflow('queja_wf', 'Quejas y sugerencias', [
      'recibida' => 'Recibida',
      'en_investigacion' => 'En investigación',
      'accion_tomada' => 'Acción tomada',
      'cerrada' => 'Cerrada',
    ], 'recibida', ['queja']);

    $this->ensureWorkflow('reservacion_wf', 'Reservaciones', [
      'solicitada' => 'Solicitada',
      'aprobada' => 'Aprobada',
      'realizada' => 'Realizada',
      'rechazada' => 'Rechazada',
      'cancelada' => 'Cancelada',
    ], 'solicitada', ['reservacion']);
  }

  /**
   * Intake webforms.
   */
  protected function createWebforms(): void {
    $cats = $this->termOptions('categorias_solicitud');
    $tipos = $this->termOptions('tipos_queja');

    $this->ensureWebform('solicitud_servicio', 'Nueva solicitud de servicio', <<<YAML
categoria:
  '#type': select
  '#title': Categoría
  '#options':
$cats
  '#required': true
ubicacion:
  '#type': textfield
  '#title': 'Ubicación (calle o área)'
  '#required': true
descripcion:
  '#type': textarea
  '#title': Descripción
  '#required': true
fotos:
  '#type': webform_image_file
  '#title': Fotos
  '#file_extensions': 'png jpg jpeg webp'
  '#max_filesize': '5 MB'
  '#multiple': true
  '#max_files': 5
YAML, 'solicitud');

    $this->ensureWebform('recoleccion_especial', 'Recolección especial de residuos', <<<YAML
fecha:
  '#type': date
  '#title': 'Fecha solicitada'
  '#required': true
descripcion:
  '#type': textarea
  '#title': 'Qué se recolecta'
  '#required': true
ubicacion:
  '#type': textfield
  '#title': Ubicación
  '#required': true
YAML, 'solicitud');

    $this->ensureWebform('calificar_servicio', 'Calificar servicio', <<<YAML
folio:
  '#type': textfield
  '#title': Folio
  '#required': true
estrellas:
  '#type': webform_rating
  '#title': Calificación
  '#min': 1
  '#max': 5
  '#required': true
comentario:
  '#type': textarea
  '#title': Comentario
YAML, NULL, 'rate');

    $this->ensureWebform('queja_sugerencia', 'Nueva queja o sugerencia', <<<YAML
tipo:
  '#type': select
  '#title': Tipo
  '#options':
$tipos
  '#required': true
confidencial:
  '#type': checkbox
  '#title': 'Presentar de forma confidencial (solo Administración verá tu identidad)'
descripcion:
  '#type': textarea
  '#title': Descripción
  '#required': true
fotos:
  '#type': webform_image_file
  '#title': Fotos
  '#file_extensions': 'png jpg jpeg webp'
  '#multiple': true
  '#max_files': 5
YAML, 'queja');

    $this->ensureWebform('reservar_salon', 'Solicitar reservación del salón de fiestas', <<<YAML
fecha_inicio:
  '#type': datetime
  '#title': 'Fecha y hora de inicio'
  '#required': true
fecha_fin:
  '#type': datetime
  '#title': 'Fecha y hora de fin'
  '#required': true
invitados:
  '#type': number
  '#title': 'Número de invitados'
  '#required': true
  '#min': 1
tipo_evento:
  '#type': textfield
  '#title': 'Tipo de evento'
  '#required': true
acepta_reglas:
  '#type': checkbox
  '#title': 'Acepto el reglamento del salón de fiestas y las tarifas vigentes'
  '#required': true
YAML, 'reservacion');

    $this->ensureWebform('registrar_visita', 'Registrar visita o proveedor', <<<YAML
nombre:
  '#type': textfield
  '#title': 'Nombre de la visita o proveedor'
  '#required': true
tipo:
  '#type': select
  '#title': Tipo
  '#options':
    visita: Visita
    proveedor: Proveedor
    domestico: 'Personal doméstico'
    cuidador: Cuidador
    recurrente: 'Autorización recurrente'
  '#required': true
inicio:
  '#type': datetime
  '#title': 'Inicio de la ventana'
  '#required': true
fin:
  '#type': datetime
  '#title': 'Fin de la ventana'
  '#required': true
placa:
  '#type': textfield
  '#title': Placa
personas:
  '#type': number
  '#title': 'Número de personas'
  '#min': 1
  '#default_value': 1
caducidad:
  '#type': date
  '#title': 'Caducidad (autorizaciones recurrentes)'
recurrente:
  '#type': checkbox
  '#title': Recurrente
YAML, 'visita');

    $this->ensureWebform('reportar_incidente', 'Reportar incidente', <<<YAML
fecha:
  '#type': datetime
  '#title': 'Fecha y hora'
  '#required': true
lugar:
  '#type': textfield
  '#title': Lugar
  '#required': true
descripcion:
  '#type': textarea
  '#title': Descripción
  '#required': true
fotos:
  '#type': webform_image_file
  '#title': Fotos
  '#file_extensions': 'png jpg jpeg webp'
  '#multiple': true
  '#max_files': 5
YAML, 'incidente');
  }

  /**
   * Menus and links.
   */
  protected function createMenus(): void {
    $menus = [
      'staff' => 'Menú de personal',
      'guardhouse' => 'Menú de caseta',
    ];
    foreach ($menus as $id => $label) {
      if (!\Drupal::entityTypeManager()->getStorage('menu')->load($id)) {
        \Drupal::entityTypeManager()->getStorage('menu')->create([
          'id' => $id,
          'label' => $label,
        ])->save();
      }
    }

    $this->clearMenu('main');
    $this->clearMenu('footer');

    $main = [
      ['Inicio', '/inicio'],
      ['Solicitudes', '/solicitudes'],
      ['Quejas y sugerencias', '/quejas'],
      ['Instalaciones', '/instalaciones'],
      ['Seguridad y acceso', '/seguridad'],
      ['Comunidad', '/comunidad'],
      ['Contacto', '/contacto'],
    ];
    foreach ($main as $i => [$title, $uri]) {
      $this->menuLink('main', $title, $uri, $i);
    }

    $footer = [
      ['Aviso de privacidad', '/aviso-de-privacidad'],
      ['Términos de uso', '/terminos-de-uso'],
      ['Accesibilidad', '/accesibilidad'],
      ['Mapa del sitio', '/mapa-del-sitio'],
      ['Contacto', '/contacto'],
      ['Así lo construimos', '/asi-lo-construimos'],
    ];
    foreach ($footer as $i => [$title, $uri]) {
      $this->menuLink('footer', $title, $uri, $i);
    }

    $this->menuLink('staff', 'Panel de administración', '/admin/provenza', 0);
    $this->menuLink('staff', 'Bandeja unificada', '/admin/provenza/bandeja', 1);
    $this->menuLink('staff', 'Reportes', '/admin/provenza/reportes', 2);
    $this->menuLink('guardhouse', 'Vista de caseta', '/caseta', 0);
    $this->clearMenu('account');
  }

  /**
   * Pathauto patterns.
   */
  protected function createPathauto(): void {
    $patterns = [
      'instalacion' => '/instalaciones/[node:title]',
      'aviso' => '/comunidad/avisos/[node:title]',
      'documento' => '/comunidad/documentos/[node:title]',
      'faq' => '/comunidad/preguntas-frecuentes/[node:title]',
      'tarifa' => '/comunidad/cuotas-y-tarifas/[node:title]',
      'evento' => '/comunidad/calendario/[node:title]',
      'modulo_documentado' => '/asi-lo-construimos/modulos/[node:title]',
      'page' => '/[node:title]',
      'solicitud' => '/solicitudes/[node:field_folio:value]',
      'queja' => '/quejas/[node:field_folio:value]',
      'reservacion' => '/reservaciones/[node:field_folio:value]',
      'visita' => '/seguridad/pases/[node:field_folio:value]',
      'incidente' => '/seguridad/incidentes/[node:field_folio:value]',
    ];
    foreach ($patterns as $bundle => $pattern) {
      $id = 'provenza_' . $bundle;
      if (PathautoPattern::load($id)) {
        continue;
      }
      $entity = PathautoPattern::create([
        'id' => $id,
        'label' => 'URL ' . $bundle,
        'type' => 'canonical_entities:node',
        'pattern' => $pattern,
        'weight' => -5,
      ]);
      $entity->addSelectionCondition([
        'id' => 'entity_bundle:node',
        'negate' => FALSE,
        'uuid' => \Drupal::service('uuid')->generate(),
        'context_mapping' => ['node' => 'node'],
        'bundles' => [$bundle => $bundle],
      ]);
      $entity->save();
    }
  }

  /**
   * Flags for bookmarks and priority.
   */
  protected function createFlags(): void {
    if (!Flag::load('guardar')) {
      Flag::create([
        'id' => 'guardar',
        'label' => 'Guardar',
        'entity_type' => 'node',
        'bundles' => ['documento', 'faq'],
        'flag_short' => 'Guardar',
        'unflag_short' => 'Quitar de guardados',
        'flag_message' => 'Guardado.',
        'unflag_message' => 'Eliminado de guardados.',
        'flag_type' => 'entity:node',
        'link_type' => 'reload',
        'global' => FALSE,
        'flagTypeConfig' => [],
        'linkTypeConfig' => [],
      ])->save();
    }
    if (!Flag::load('prioridad')) {
      Flag::create([
        'id' => 'prioridad',
        'label' => 'Prioridad',
        'entity_type' => 'node',
        'bundles' => ['solicitud', 'queja', 'incidente'],
        'flag_short' => 'Marcar prioridad',
        'unflag_short' => 'Quitar prioridad',
        'flag_type' => 'entity:node',
        'link_type' => 'reload',
        'global' => TRUE,
        'flagTypeConfig' => [],
        'linkTypeConfig' => [],
      ])->save();
    }
  }

  /**
   * Search API database index excluding the tutorial.
   */
  protected function createSearch(): void {
    if (!Server::load('provenza_db')) {
      Server::create([
        'id' => 'provenza_db',
        'name' => 'Base de datos Provenza',
        'backend' => 'search_api_db',
        'backend_config' => [
          'database' => 'default:default',
          'min_chars' => 3,
          'matching' => 'partial',
        ],
      ])->save();
    }
    if (!Index::load('contenido')) {
      $index = Index::create([
        'id' => 'contenido',
        'name' => 'Contenido público y permitido',
        'server' => 'provenza_db',
        'options' => [
          'cron_limit' => 50,
          'index_directly' => TRUE,
        ],
        'datasource_settings' => [
          'entity:node' => [
            'plugin_id' => 'entity:node',
            'settings' => [
              'bundles' => [
                'default' => TRUE,
                'selected' => ['modulo_documentado'],
              ],
              'languages' => ['default' => TRUE, 'selected' => []],
            ],
          ],
        ],
        'processor_settings' => [
          'rendered_item' => [
            'weights' => ['add_properties' => 0],
            'roles' => ['anonymous'],
            'view_mode' => ['entity:node' => ['default' => 'teaser']],
          ],
        ],
        'field_settings' => [
          'title' => [
            'label' => 'Título',
            'datasource_id' => 'entity:node',
            'property_path' => 'title',
            'type' => 'text',
          ],
          'status' => [
            'label' => 'Publicado',
            'datasource_id' => 'entity:node',
            'property_path' => 'status',
            'type' => 'boolean',
          ],
        ],
      ]);
      // Invert bundle selection: selected = excluded when default TRUE? Search API
      // bundles.default TRUE means all except selected. Selected modulo_documentado
      // is excluded. Good.
      $index->save();
    }
    $this->excludeTutorialFromSearch();
  }

  /**
   * Password policy, TFA encryption, metatag noindex for tutorial.
   */
  protected function createSecurity(): void {
    if (!Key::load('tfa_key')) {
      Key::create([
        'id' => 'tfa_key',
        'label' => 'Clave TFA',
        'key_type' => 'encryption',
        'key_type_settings' => ['key_size' => 256],
        'key_provider' => 'config',
        'key_provider_settings' => [
          'key_value' => bin2hex(random_bytes(32)),
          'base64_encoded' => FALSE,
        ],
      ])->save();
    }
    if (!EncryptionProfile::load('tfa')) {
      EncryptionProfile::create([
        'id' => 'tfa',
        'label' => 'Cifrado TFA',
        'encryption_method' => 'real_aes',
        'encryption_key' => 'tfa_key',
      ])->save();
    }

    \Drupal::configFactory()->getEditable('tfa.settings')
      ->set('enabled', 1)
      ->set('required_roles', [
        'administracion' => 'administracion',
        'webmaster' => 'webmaster',
      ])
      ->set('encryption', 'tfa')
      ->set('default_validation_plugin', 'tfa_totp')
      ->set('allowed_validation_plugins', [
        'tfa_totp' => 'tfa_totp',
        'tfa_recovery_code' => 'tfa_recovery_code',
      ])
      ->set('validation_plugin_settings', [
        'tfa_totp' => ['time_skew' => 2, 'site_name_prefix' => 1, 'issuer' => 'Provenza Residencial'],
        'tfa_recovery_code' => ['recovery_codes_amount' => 10],
      ])
      ->set('login_plugin_settings', [])
      ->set('reset_passwords', FALSE)
      ->set('users_without_tfa_redirect', TRUE)
      ->save();

    if (!\Drupal::entityTypeManager()->getStorage('password_policy')->load('provenza')) {
      \Drupal::entityTypeManager()->getStorage('password_policy')->create([
        'id' => 'provenza',
        'label' => 'Política de contraseñas Provenza',
        'password_reset' => 0,
        'roles' => [
          'authenticated' => 'authenticated',
          'residente_titular' => 'residente_titular',
          'residente_familiar' => 'residente_familiar',
          'caseta' => 'caseta',
          'mantenimiento' => 'mantenimiento',
          'administracion' => 'administracion',
          'mesa_directiva' => 'mesa_directiva',
          'webmaster' => 'webmaster',
        ],
        'policy_constraints' => [
          'password_length_1' => [
            'id' => 'password_length',
            'character_length' => 10,
            'character_operation' => 'minimum',
          ],
          'character_types_1' => [
            'id' => 'character_types',
            'character_types' => 3,
          ],
          'password_username_1' => [
            'id' => 'password_username',
            'disallow_username' => TRUE,
          ],
        ],
        'send_reset_email' => FALSE,
        'send_pending_email' => [0],
        'show_policy_table' => TRUE,
      ])->save();
    }

    if ($type = NodeType::load('modulo_documentado')) {
      // Metatag defaults via config.
    }
    $metatag = \Drupal::configFactory()->getEditable('metatag.metatag_defaults.node__modulo_documentado');
    $metatag->set('tags', ['robots' => 'noindex, nofollow, noarchive'])->save();
  }

  /**
   * Admin queue view. Public listings use PortalController so amounts stay visible.
   */
  protected function createViews(): void {
    $this->deleteCollidingViews();
    $this->createBandejaView();
  }

  /**
   * Static pages and aliases for section landings.
   */
  protected function createPages(): void {
    $pages = [
      'Aviso de privacidad' => [
        'alias' => '/aviso-de-privacidad',
        'body' => '<p>Este aviso describe el tratamiento de datos personales de colonos, visitas y personal de Provenza Residencial, Av. Adolfo López Mateos Sur 5555, Tlajomulco de Zúñiga, Jalisco, C.P. 45645.</p><p>Los datos se usan para control de acceso, solicitudes de servicio, reservaciones y comunicación con Administración. No se venden a terceros. Para ejercer derechos ARCO escriba a Administración.</p>',
      ],
      'Términos de uso' => [
        'alias' => '/terminos-de-uso',
        'body' => '<p>El uso de este portal implica aceptar el reglamento interno del condominio y usar las cuentas de forma personal e intransferible. Las solicitudes, quejas y reservaciones quedan registradas con folio para su seguimiento.</p>',
      ],
      'Accesibilidad' => [
        'alias' => '/accesibilidad',
        'body' => '<p>El portal busca cumplir los criterios básicos de WCAG 2.2 nivel AA: contraste, navegación por teclado, textos alternativos y etiquetas en formularios. Si encuentra una barrera, escríbanos en Contacto.</p>',
      ],
      'Reglamento de acceso' => [
        'alias' => '/seguridad/reglamento-de-acceso',
        'body' => '<h2>Visitas</h2><p>Toda visita debe pre-registrarse. El personal de caseta valida el pase QR.</p><h2>Proveedores</h2><p>Los proveedores se registran con ventana horaria y placa.</p><h2>Mudanzas y obras</h2><p>Las mudanzas y obras se avisan a Administración con al menos 48 horas de anticipación.</p>',
      ],
      'Teléfonos de emergencia' => [
        'alias' => '/seguridad/telefonos-de-emergencia',
        'body' => '<ul><li>Caseta: <a href="tel:+523312345678">33 1234 5678</a></li><li>Administración: <a href="tel:+523387654321">33 8765 4321</a></li><li>Protección Civil Tlajomulco: <a href="tel:+523336840800">33 3684 0800</a></li><li>Emergencias: <a href="tel:911">911</a></li></ul>',
      ],
      'Comunidad' => [
        'alias' => '/comunidad',
        'body' => '<p>Avisos, calendario, cuotas, documentos, actas, preguntas frecuentes y directorio de Provenza Residencial.</p><ul><li><a href="/comunidad/avisos">Avisos</a></li><li><a href="/comunidad/calendario">Calendario de eventos</a></li><li><a href="/comunidad/cuotas-y-tarifas">Cuotas y tarifas</a></li><li><a href="/comunidad/documentos">Documentos y reglamento</a></li><li><a href="/comunidad/actas">Actas de asamblea</a></li><li><a href="/comunidad/preguntas-frecuentes">Preguntas frecuentes</a></li><li><a href="/comunidad/directorio">Directorio</a></li></ul>',
      ],
      'Seguridad y acceso' => [
        'alias' => '/seguridad',
        'body' => '<p>Pre-registro de visitas, pases QR, vehículos, incidentes y teléfonos de emergencia.</p><ul><li><a href="/form/registrar-visita">Registrar visita o proveedor</a></li><li><a href="/seguridad/mis-autorizaciones">Mis autorizaciones y pases QR</a></li><li><a href="/seguridad/mis-vehiculos">Mis vehículos y tags</a></li><li><a href="/form/reportar-incidente">Reportar incidente</a></li><li><a href="/seguridad/reglamento-de-acceso">Reglamento de acceso</a></li><li><a href="/seguridad/telefonos-de-emergencia">Teléfonos de emergencia</a></li></ul>',
      ],
      'Solicitudes de servicio' => [
        'alias' => '/solicitudes',
        'body' => '<ul><li><a href="/form/solicitud-servicio">Nueva solicitud</a></li><li><a href="/solicitudes/mis-solicitudes">Mis solicitudes y seguimiento</a></li><li><a href="/form/recoleccion-especial">Recolección especial de residuos</a></li><li><a href="/form/calificar-servicio">Calificar servicio</a></li></ul>',
      ],
      'Quejas y sugerencias' => [
        'alias' => '/quejas',
        'body' => '<ul><li><a href="/form/queja-sugerencia">Nueva queja o sugerencia</a></li><li><a href="/quejas/mis-quejas">Mis quejas y seguimiento</a></li><li><a href="/quejas/reporte-confidencial">Reporte confidencial</a></li></ul>',
      ],
      'Contacto' => [
        'alias' => '/contacto',
        'body' => '<p>Administración de Provenza Residencial. Av. Adolfo López Mateos Sur 5555, Tlajomulco de Zúñiga, Jalisco.</p><p><a href="/contact">Escribir a Administración</a></p>',
      ],
    ];
    foreach ($pages as $title => $info) {
      $this->ensurePage($title, $info['body'], $info['alias']);
    }
  }

  /**
   * Places custom and core blocks.
   */
  protected function placeBlocks(): void {
    $this->placeBlock('provenza_quick_actions', 'provenza_quick_actions', 'content_above', -10, '<front>');
    $this->placeBlock('provenza_urgent', 'provenza_urgent_notices', 'highlighted', -20, NULL);
    $this->placeBlock('provenza_search', 'provenza_search_form', 'secondary_menu', -5, NULL);
    $this->placeBlock('provenza_staff_menu', 'system_menu_block:staff', 'sidebar', -10, NULL, [
      'administracion', 'webmaster', 'mesa_directiva', 'mantenimiento',
    ]);
    $this->placeBlock('provenza_caseta_menu', 'system_menu_block:guardhouse', 'sidebar', -9, NULL, [
      'caseta', 'administracion', 'webmaster',
    ]);
    foreach (['olivero_search_form_wide', 'olivero_search_form_narrow'] as $id) {
      $block = Block::load($id);
      if ($block) {
        $block->setStatus(FALSE)->save();
      }
    }
  }

  /**
   * ECA models for status-change emails.
   */
  protected function createEcaModels(): void {
    if (Eca::load('provenza_status_mail')) {
      return;
    }
    try {
    Eca::create([
      'id' => 'provenza_status_mail',
      'label' => 'Correos de cambio de estado',
      'modeller' => 'fallback',
      'status' => TRUE,
      'version' => '1.0',
      'weight' => 0,
      'events' => [
        'Event_status' => [
          'plugin' => 'workflow:transition',
          'label' => 'Cambio de estado',
          'configuration' => [
            'type' => 'node _all',
            'from_state' => '',
            'to_state' => '',
          ],
          'successors' => [
            ['id' => 'Activity_mail', 'condition' => ''],
          ],
        ],
      ],
      'conditions' => [],
      'gateways' => [],
      'actions' => [
        'Activity_mail' => [
          'plugin' => 'action_send_email_action',
          'label' => 'Enviar correo (plantilla Provenza)',
          'configuration' => [
            'recipient' => '[node:author:mail]',
            'subject' => 'Actualización de [node:folio]',
            'message' => "Hola [node:author:display-name],\n\nEl folio [node:folio] cambió de estado.\n\n[node:url:absolute]\n\nPortal de colonos · Provenza Residencial",
          ],
          'successors' => [
            ['id' => 'Activity_log', 'condition' => ''],
          ],
        ],
        'Activity_log' => [
          'plugin' => 'eca_write_log_message',
          'label' => 'Registrar en auditoría',
          'configuration' => [
            'channel' => 'auditoria',
            'severity' => 6,
            'message' => 'ECA: correo de estado para [node:folio]',
          ],
          'successors' => [],
        ],
      ],
    ])->save();
    }
    catch (\Throwable $e) {
      \Drupal::logger('provenza_core')->warning('ECA: @m', ['@m' => $e->getMessage()]);
    }
  }

  /**
   * Role permissions.
   */
  protected function grantPermissions(): void {
    $anon = [
      'access content', 'access site-wide contact form', 'view media',
      'entity print access bundle documento',
    ];
    $auth = array_merge($anon, [
      'view own unpublished content', 'access user profiles', 'flag guardar',
      'unflag guardar', 'use text format basic_html',
    ]);
    $resident = array_merge($auth, [
      'create solicitud content', 'create queja content', 'create reservacion content',
      'create visita content', 'create incidente content',
      'edit own solicitud content', 'edit own queja content', 'edit own reservacion content',
      'edit own visita content',
      'access webform overview',
      'entity print access bundle documento',
      'entity print access bundle reservacion',
    ]);
    $titular = array_merge($resident, ['manage household members']);
    $caseta = array_merge($auth, [
      'access caseta view', 'create incidente content', 'edit any visita content',
      'edit any incidente content',
    ]);
    $maint = array_merge($auth, ['update assigned requests']);
    $admin = array_merge($resident, [
      'access provenza admin', 'view confidential complaints', 'assign service requests',
      'manage provenza residents', 'view management reports', 'view all tracking content',
      'administer users', 'access user contact forms',
      'create aviso content', 'edit any aviso content', 'delete any aviso content',
      'create evento content', 'edit any evento content',
      'create documento content', 'edit any documento content',
      'create tarifa content', 'edit any tarifa content',
      'create instalacion content', 'edit any instalacion content',
      'create faq content', 'edit any faq content',
      'create directorio content', 'edit any directorio content',
      'create modulo_documentado content', 'edit any modulo_documentado content',
      'create page content', 'edit any page content',
      'edit any solicitud content', 'edit any queja content', 'edit any reservacion content',
      'edit any visita content', 'edit any incidente content', 'edit any domicilio content',
      'create domicilio content',
      'use solicitud_wf transition crear', 'view latest version',
      'view any unpublished content',
      'flag prioridad', 'unflag prioridad',
      'access toolbar', 'view the administration theme',
      'administer contact forms',
    ]);
    $board = array_merge($auth, [
      'view management reports', 'create documento content', 'edit own documento content',
      'access toolbar', 'view the administration theme',
    ]);
    $webmaster = array_merge($admin, [
      'administer site configuration', 'administer nodes', 'administer users',
      'administer permissions', 'administer menu', 'administer blocks',
      'administer views', 'administer webform', 'access content overview',
      'administer modules', 'administer themes',
    ]);

    $this->rolePerms('anonymous', $anon);
    $this->rolePerms('authenticated', $auth);
    $this->rolePerms('residente_titular', $titular);
    $this->rolePerms('residente_familiar', $resident);
    $this->rolePerms('caseta', $caseta);
    $this->rolePerms('mantenimiento', $maint);
    $this->rolePerms('administracion', $admin);
    $this->rolePerms('mesa_directiva', $board);
    $this->rolePerms('webmaster', $webmaster);

    // Grant all content_moderation transitions to administración.
    foreach (['solicitud_wf', 'queja_wf', 'reservacion_wf'] as $wf) {
      $workflow = Workflow::load($wf);
      if (!$workflow) {
        continue;
      }
      foreach (array_keys($workflow->getTypePlugin()->getTransitions()) as $tid) {
        $this->rolePerms('administracion', ["use $wf transition $tid"]);
        $this->rolePerms('webmaster', ["use $wf transition $tid"]);
      }
    }
    $this->rolePerms('mantenimiento', [
      'use solicitud_wf transition a_en_proceso',
      'use solicitud_wf transition a_resuelta',
    ]);
    $this->rolePerms('residente_titular', ['use reservacion_wf transition a_cancelada']);
    $this->rolePerms('residente_familiar', ['use reservacion_wf transition a_cancelada']);
  }

  /**
   * Aligns menus, search, displays and recaptcha with the requirements brief.
   */
  public function applyRequirementsFixes(): void {
    $this->createMenus();
    $this->placeBlocks();
    $this->createPages();
    $this->excludeTutorialFromSearch();
    $this->hideCrudFieldsOnViewDisplays();
    $this->configureCurrency();
    $this->ensureFeedbackContactForm();
    $this->closeEnglishContactWebform();
    $this->configureCaptcha();
    $this->configureSitemapExclusion();
    $this->showPageBody();
    $this->enableFacilityLayoutBuilder();
    $this->hideFacilityInternalCode();
    $this->enablePrintAndResponsiveImages();
    $this->createViews();
    $this->hideRegisterWidgets();
    $this->grantPrintPermissions();
    $form = $this->ensureFormDisplay('user', 'user');
    $this->configureUserForm($form);
    $form->save();
    \Drupal::configFactory()->getEditable('charts.settings')
      ->set('charts_default_settings.library', 'chartjs')
      ->set('charts_default_settings.type', 'column')
      ->save();
    \Drupal::configFactory()->getEditable('webform.settings')
      ->set('file.file_private_redirect_message', 'Inicia sesión para ver el archivo adjunto.')
      ->set('settings.default_confirmation_back_label', 'Volver al formulario')
      ->save();
    if ($wf = Webform::load('queja_sugerencia')) {
      $settings = $wf->getSettings();
      $settings['form_prepopulate'] = TRUE;
      $wf->setSettings($settings);
      $wf->save();
    }
  }

  /**
   * User profile field groups so Field Group is actually used.
   */
  protected function configureUserForm(EntityFormDisplay $form): void {
    $widgets = [
      'field_nombre_completo' => ['type' => 'string_textfield', 'weight' => 1],
      'field_telefono' => ['type' => 'telephone_default', 'weight' => 2],
      'field_contacto_emergencia_nom' => ['type' => 'string_textfield', 'weight' => 3],
      'field_contacto_emergencia_tel' => ['type' => 'telephone_default', 'weight' => 4],
      'field_domicilio' => ['type' => 'entity_reference_autocomplete', 'weight' => 5],
      'field_relacion' => ['type' => 'options_select', 'weight' => 6],
      'field_numero_casa' => ['type' => 'string_textfield', 'weight' => 7],
      'field_vehiculos' => ['type' => 'paragraphs', 'weight' => 8],
      'field_mascotas' => ['type' => 'paragraphs', 'weight' => 9],
      'field_notif_solicitudes' => ['type' => 'boolean_checkbox', 'weight' => 10, 'settings' => ['display_label' => TRUE]],
      'field_notif_avisos' => ['type' => 'boolean_checkbox', 'weight' => 11, 'settings' => ['display_label' => TRUE]],
      'field_notif_reservaciones' => ['type' => 'boolean_checkbox', 'weight' => 12, 'settings' => ['display_label' => TRUE]],
    ];
    foreach ($widgets as $name => $component) {
      $form->setComponent($name, $component);
    }
    $groups = [
      'group_perfil' => [
        'children' => ['field_nombre_completo', 'field_telefono', 'field_contacto_emergencia_nom', 'field_contacto_emergencia_tel'],
        'label' => 'Datos personales',
        'weight' => 1,
      ],
      'group_domicilio' => [
        'children' => ['field_domicilio', 'field_relacion', 'field_numero_casa', 'field_vehiculos', 'field_mascotas'],
        'label' => 'Domicilio, vehículos y mascotas',
        'weight' => 2,
      ],
      'group_notificaciones' => [
        'children' => ['field_notif_solicitudes', 'field_notif_avisos', 'field_notif_reservaciones'],
        'label' => 'Notificaciones por correo',
        'weight' => 3,
      ],
    ];
    foreach ($groups as $id => $group) {
      $form->setThirdPartySetting('field_group', $id, [
        'children' => $group['children'],
        'label' => $group['label'],
        'region' => 'content',
        'parent_name' => '',
        'weight' => $group['weight'],
        'format_type' => 'details',
        'format_settings' => [
          'label' => $group['label'],
          'open' => TRUE,
          'required_fields' => TRUE,
        ],
      ]);
    }
  }

  protected function excludeTutorialFromSearch(): void {
    $index = Index::load('contenido');
    if (!$index) {
      return;
    }
    try {
      if ($index->isValidDatasource('entity:node')) {
        $datasource = $index->getDatasource('entity:node');
        $config = $datasource->getConfiguration();
        $config['bundles'] = [
          'default' => TRUE,
          'selected' => ['modulo_documentado'],
        ];
        $datasource->setConfiguration($config);
        $index->save();
      }
    }
    catch (\Throwable $e) {
      \Drupal::logger('provenza_core')->warning('Search API: @m', ['@m' => $e->getMessage()]);
    }
  }

  protected function hideCrudFieldsOnViewDisplays(): void {
    $storage = \Drupal::entityTypeManager()->getStorage('entity_view_display');
    foreach ($storage->loadMultiple() as $display) {
      if ($display->getTargetEntityTypeId() !== 'node') {
        continue;
      }
      foreach (['langcode', 'path', 'publish_on', 'unpublish_on', 'layout_builder__layout'] as $name) {
        $display->removeComponent($name);
      }
      $body = $display->getComponent('body');
      if ($body && ($body['type'] ?? '') === 'string') {
        $body['type'] = 'text_default';
        $display->setComponent('body', $body);
      }
      if ($display->getTargetBundle() === 'tarifa') {
        $display->setComponent('field_monto', [
          'type' => 'number_decimal',
          'label' => 'above',
          'settings' => [
            'thousand_separator' => ',',
            'decimal_separator' => '.',
            'scale' => 0,
            'prefix_suffix' => TRUE,
          ],
          'weight' => 1,
        ]);
      }
      $display->save();
    }
  }

  protected function configureCurrency(): void {
    $field = FieldConfig::loadByName('node', 'tarifa', 'field_monto');
    if ($field) {
      $field->setSetting('prefix', '$');
      $field->save();
    }
  }

  protected function closeEnglishContactWebform(): void {
    $webform = Webform::load('contact');
    if ($webform) {
      $webform->setStatus(WebformInterface::STATUS_CLOSED);
      $webform->set('title', 'Contacto (formulario retirado)');
      $webform->set('description', 'Use el formulario de Contacto del menú principal.');
      $webform->set('elements', <<<YAML
name:
  '#title': Nombre
  '#type': textfield
  '#required': true
  '#default_value': '[current-user:display-name]'
email:
  '#title': Correo
  '#type': email
  '#required': true
  '#default_value': '[current-user:mail]'
subject:
  '#title': Asunto
  '#type': textfield
  '#required': true
message:
  '#title': Mensaje
  '#type': textarea
  '#required': true
actions:
  '#type': webform_actions
  '#title': 'Botón de envío'
  '#submit__label': Enviar
YAML);
      $settings = $webform->getSettings();
      $settings['form_close_message'] = '<p>Este formulario ya no se usa. Escriba a Administración desde <a href="/contact">Contacto</a>.</p>';
      $webform->setSettings($settings);
      $webform->save();
    }
  }

  /**
   * Core contact form in Spanish, routed to Administración.
   */
  protected function ensureFeedbackContactForm(): void {
    $form = ContactForm::load('feedback');
    if (!$form) {
      $form = ContactForm::create(['id' => 'feedback']);
    }
    $form->set('label', 'Contacto');
    $form->setRecipients(['administracion@provenza.local']);
    $form->setMessage('Gracias. Tu mensaje fue enviado a Administración.');
    $form->setRedirectPath('/contacto');
    $form->setReply('');
    $form->save();
    \Drupal::configFactory()->getEditable('contact.settings')
      ->set('default_form', 'feedback')
      ->save();
  }

  protected function configureCaptcha(): void {
    if (!class_exists(ReCaptchaV3Action::class)) {
      return;
    }
    \Drupal::configFactory()->getEditable('recaptcha_v3.settings')
      ->set('site_key', '6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI')
      ->set('secret_key', '6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe')
      ->set('verify_hostname', FALSE)
      ->set('hide_badge', FALSE)
      ->set('error_message', 'No se pudo verificar que no eres un robot. Inténtalo de nuevo.')
      ->set('default_challenge', 'captcha/Math')
      ->save();
    if (!ReCaptchaV3Action::load('provenza')) {
      ReCaptchaV3Action::create([
        'id' => 'provenza',
        'label' => 'Portal Provenza',
        'threshold' => 0.5,
        'challenge' => 'default',
      ])->save();
    }
    foreach ([
      'user_register_form' => 'Registro',
      'contact_message_feedback_form' => 'Contacto',
      'webform_submission_queja_sugerencia_add_form' => 'Queja o sugerencia',
    ] as $form_id => $label) {
      $point = CaptchaPoint::load($form_id);
      if (!$point) {
        $point = CaptchaPoint::create([
          'formId' => $form_id,
          'label' => $label,
        ]);
      }
      $point->set('captchaType', 'recaptcha_v3/provenza');
      $point->set('label', $label);
      $point->setStatus(TRUE);
      $point->save();
    }
  }

  /**
   * Hub pages (Solicitudes, Quejas, Comunidad…) must show their body links.
   */
  protected function showPageBody(): void {
    $display = EntityViewDisplay::load('node.page.default');
    if (!$display) {
      return;
    }
    if (method_exists($display, 'disableLayoutBuilder')) {
      $display->disableLayoutBuilder();
    }
    else {
      $display->unsetThirdPartySetting('layout_builder', 'enabled');
      $display->unsetThirdPartySetting('layout_builder', 'allow_custom');
      $display->unsetThirdPartySetting('layout_builder', 'sections');
    }
    $display->setComponent('body', [
      'type' => 'text_default',
      'label' => 'hidden',
      'weight' => 0,
      'settings' => [],
    ]);
    $display->save();
  }

  /**
   * Keep Layout Builder on instalaciones after hub pages go back to body.
   */
  protected function enableFacilityLayoutBuilder(): void {
    $display = EntityViewDisplay::load('node.instalacion.default');
    if (!$display) {
      return;
    }
    $display->removeComponent('layout_builder__layout');
    if (method_exists($display, 'enableLayoutBuilder')) {
      $display->enableLayoutBuilder();
    }
    else {
      $display->setThirdPartySetting('layout_builder', 'enabled', TRUE);
    }
    $display->setThirdPartySetting('layout_builder', 'allow_custom', TRUE);
    $display->save();
  }

  protected function configureSitemapExclusion(): void {
    \Drupal::configFactory()->getEditable('simple_sitemap.settings')
      ->set('enabled_entity_types', ['node', 'taxonomy_term'])
      ->save();
    \Drupal::configFactory()->getEditable('simple_sitemap.bundle_settings.default.node.modulo_documentado')
      ->set('index', FALSE)
      ->set('priority', '0.5')
      ->set('changefreq', '')
      ->set('include_images', FALSE)
      ->save();
  }

  /**
   * Removes listing views that stole public controller paths.
   */
  protected function deleteCollidingViews(): void {
    foreach ([
      'cuotas_tarifas', 'avisos_lista', 'faqs_lista', 'documentos_lista',
      'actas_lista', 'directorio_lista', 'instalaciones_lista', 'eventos_lista',
      'modulos_documentados', 'solicitudes_admin',
    ] as $id) {
      if ($view = View::load($id)) {
        $view->delete();
      }
    }
  }

  /**
   * Unified queue with BEF, VBO and CSV export.
   */
  protected function createBandejaView(): void {
    if (View::load('provenza_bandeja')) {
      return;
    }
    try {
    $types = [
      'solicitud' => 'solicitud',
      'queja' => 'queja',
      'reservacion' => 'reservacion',
      'incidente' => 'incidente',
    ];
    $states = [];
    foreach ([
      'solicitud_wf' => ['recibida', 'en_revision', 'asignada', 'en_proceso', 'resuelta', 'cerrada', 'rechazada'],
      'queja_wf' => ['recibida', 'en_investigacion', 'accion_tomada', 'cerrada'],
      'reservacion_wf' => ['solicitada', 'aprobada', 'realizada', 'rechazada', 'cancelada'],
    ] as $wf => $ids) {
      foreach ($ids as $sid) {
        $states["$wf-$sid"] = "$wf-$sid";
      }
    }
    View::create([
      'id' => 'provenza_bandeja',
      'label' => 'Bandeja unificada',
      'module' => 'views',
      'description' => 'Solicitudes, quejas, reservaciones e incidentes.',
      'base_table' => 'node_field_data',
      'base_field' => 'nid',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_title' => 'Default',
          'position' => 0,
          'display_options' => [
            'title' => 'Bandeja unificada',
            'access' => ['type' => 'perm', 'options' => ['perm' => 'access provenza admin']],
            'cache' => ['type' => 'tag', 'options' => []],
            'query' => ['type' => 'views_query', 'options' => []],
            'exposed_form' => [
              'type' => 'bef',
              'options' => [
                'submit_button' => 'Filtrar',
                'reset_button' => TRUE,
                'reset_button_label' => 'Quitar filtros',
                'exposed_sorts_label' => 'Ordenar por',
                'expose_sort_order' => TRUE,
                'sort_asc_label' => 'Asc',
                'sort_desc_label' => 'Desc',
                'text_input_required' => '',
                'text_input_required_format' => 'basic_html',
                'bef' => [
                  'general' => [
                    'autosubmit' => FALSE,
                    'autosubmit_exclude_textfield' => TRUE,
                    'autosubmit_textfield_delay' => 500,
                    'autosubmit_hide' => FALSE,
                    'input_required' => FALSE,
                    'allow_secondary' => FALSE,
                    'secondary_label' => 'Opciones avanzadas',
                    'secondary_open' => FALSE,
                    'reset_button_always_show' => FALSE,
                  ],
                  'filter' => [
                    'type_1' => [
                      'plugin_id' => 'default',
                      'advanced' => [
                        'sort_options' => FALSE,
                        'rewrite' => ['filter_rewrite_values' => "solicitud|Solicitudes\nqueja|Quejas\nreservacion|Reservaciones\nincidente|Incidentes"],
                      ],
                    ],
                    'moderation_state' => [
                      'plugin_id' => 'default',
                      'advanced' => ['sort_options' => TRUE],
                    ],
                  ],
                ],
              ],
            ],
            'pager' => ['type' => 'full', 'options' => ['items_per_page' => 50]],
            'style' => ['type' => 'table', 'options' => ['columns' => [], 'default' => '-1', 'info' => []]],
            'row' => ['type' => 'fields'],
            'fields' => [
              'views_bulk_operations_bulk_form' => [
                'id' => 'views_bulk_operations_bulk_form',
                'table' => 'views',
                'field' => 'views_bulk_operations_bulk_form',
                'plugin_id' => 'views_bulk_operations_bulk_form',
                'label' => 'Acciones',
                'batch' => FALSE,
                'form_step' => TRUE,
                'buttons' => TRUE,
                'action_title' => 'Acción',
                'selected_actions' => [
                  ['action_id' => 'provenza_assign_maintenance', 'preconfiguration' => ['label_override' => 'Asignar a mantenimiento']],
                  ['action_id' => 'provenza_change_state', 'preconfiguration' => ['label_override' => 'Cambiar estado']],
                ],
              ],
              'field_folio' => [
                'id' => 'field_folio',
                'table' => 'node__field_folio',
                'field' => 'field_folio',
                'plugin_id' => 'field',
                'entity_type' => 'node',
                'entity_field' => 'field_folio',
                'label' => 'Folio',
                'type' => 'string',
                'settings' => ['link_to_entity' => FALSE],
              ],
              'type' => [
                'id' => 'type',
                'table' => 'node_field_data',
                'field' => 'type',
                'plugin_id' => 'field',
                'entity_type' => 'node',
                'entity_field' => 'type',
                'label' => 'Tipo',
                'type' => 'entity_reference_label',
                'settings' => ['link' => FALSE],
              ],
              'title' => [
                'id' => 'title',
                'table' => 'node_field_data',
                'field' => 'title',
                'plugin_id' => 'field',
                'entity_type' => 'node',
                'entity_field' => 'title',
                'label' => 'Título',
                'type' => 'string',
                'settings' => ['link_to_entity' => TRUE],
              ],
              'moderation_state' => [
                'id' => 'moderation_state',
                'table' => 'node_field_data',
                'field' => 'moderation_state',
                'plugin_id' => 'field',
                'entity_type' => 'node',
                'label' => 'Estado',
                'type' => 'content_moderation_state',
              ],
              'created' => [
                'id' => 'created',
                'table' => 'node_field_data',
                'field' => 'created',
                'plugin_id' => 'field',
                'entity_type' => 'node',
                'entity_field' => 'created',
                'label' => 'Fecha',
                'type' => 'timestamp',
                'settings' => ['date_format' => 'short'],
              ],
            ],
            'filters' => [
              'type' => [
                'id' => 'type',
                'table' => 'node_field_data',
                'field' => 'type',
                'plugin_id' => 'bundle',
                'entity_type' => 'node',
                'entity_field' => 'type',
                'value' => $types,
                'exposed' => FALSE,
              ],
              'type_1' => [
                'id' => 'type_1',
                'table' => 'node_field_data',
                'field' => 'type',
                'plugin_id' => 'bundle',
                'entity_type' => 'node',
                'entity_field' => 'type',
                'value' => $types,
                'exposed' => TRUE,
                'expose' => [
                  'operator_id' => 'type_1_op',
                  'label' => 'Tipo',
                  'identifier' => 'tipo',
                  'required' => FALSE,
                  'multiple' => FALSE,
                  'reduce' => TRUE,
                ],
              ],
              'moderation_state' => [
                'id' => 'moderation_state',
                'table' => 'node_field_data',
                'field' => 'moderation_state',
                'plugin_id' => 'moderation_state_filter',
                'entity_type' => 'node',
                'operator' => 'in',
                'value' => $states,
                'exposed' => TRUE,
                'expose' => [
                  'operator_id' => 'moderation_state_op',
                  'label' => 'Estado',
                  'identifier' => 'estado',
                  'required' => FALSE,
                  'multiple' => FALSE,
                  'reduce' => TRUE,
                ],
              ],
            ],
            'sorts' => [
              'created' => [
                'id' => 'created',
                'table' => 'node_field_data',
                'field' => 'created',
                'plugin_id' => 'date',
                'entity_type' => 'node',
                'entity_field' => 'created',
                'order' => 'DESC',
              ],
            ],
            'empty' => [
              'area' => [
                'id' => 'area',
                'table' => 'views',
                'field' => 'area',
                'plugin_id' => 'text',
                'content' => ['value' => 'No hay elementos.', 'format' => 'basic_html'],
              ],
            ],
          ],
        ],
        'page_1' => [
          'display_plugin' => 'page',
          'id' => 'page_1',
          'display_title' => 'Página',
          'position' => 1,
          'display_options' => [
            'path' => 'admin/provenza/bandeja',
            'menu' => [
              'type' => 'none',
              'title' => 'Bandeja unificada',
            ],
          ],
        ],
        'data_export_1' => [
          'display_plugin' => 'data_export',
          'id' => 'data_export_1',
          'display_title' => 'CSV',
          'position' => 2,
          'display_options' => [
            'path' => 'admin/provenza/bandeja/csv',
            'filename' => 'bandeja-provenza.csv',
            'automatic_download' => TRUE,
            'export_method' => 'standard',
            'style' => [
              'type' => 'data_export',
              'options' => [
                'formats' => ['csv' => 'csv'],
                'csv_settings' => [
                  'delimiter' => ',',
                  'enclosure' => '"',
                  'escape_char' => '\\',
                  'strip_tags' => TRUE,
                  'trim' => TRUE,
                  'encoding' => 'utf8',
                ],
              ],
            ],
            'displays' => [
              'page_1' => 'page_1',
              'default' => '0',
            ],
          ],
        ],
      ],
    ])->save();
    }
    catch (\Throwable $e) {
      \Drupal::logger('provenza_core')->error('Bandeja: @m', ['@m' => $e->getMessage()]);
    }
  }

  /**
   * Hide the machine code of a facility from residents.
   */
  protected function hideFacilityInternalCode(): void {
    $config = \Drupal::configFactory()->getEditable('core.entity_view_display.node.instalacion.default');
    $sections = $config->get('third_party_settings.layout_builder.sections') ?: [];
    foreach ($sections as $s => $section) {
      foreach ($section['components'] ?? [] as $uuid => $component) {
        $id = $component['configuration']['id'] ?? '';
        if ($id === 'field_block:node:instalacion:field_codigo_instalacion') {
          unset($sections[$s]['components'][$uuid]);
        }
        if ($id === 'field_block:node:instalacion:field_fotos_publicas') {
          $sections[$s]['components'][$uuid]['configuration']['formatter'] = [
            'type' => 'responsive_image',
            'label' => 'above',
            'settings' => [
              'responsive_image_style' => 'wide',
              'image_link' => '',
              'image_loading' => ['attribute' => 'lazy'],
            ],
            'third_party_settings' => [],
          ];
        }
      }
    }
    $config->set('third_party_settings.layout_builder.sections', $sections)->save();
  }

  /**
   * Entity Print PDF on documents/reservations and responsive tutorial photos.
   */
  protected function enablePrintAndResponsiveImages(): void {
    foreach (['documento', 'reservacion'] as $bundle) {
      $display = EntityViewDisplay::load("node.$bundle.default");
      if (!$display) {
        continue;
      }
      $display->setComponent('entity_print_view_pdf', [
        'weight' => 50,
        'region' => 'content',
        'label' => 'hidden',
        'settings' => [],
        'third_party_settings' => [],
      ]);
      $display->setThirdPartySetting('entity_print', 'pdf_label', 'Descargar PDF');
      $display->save();
    }
    $mod = EntityViewDisplay::load('node.modulo_documentado.default');
    if ($mod) {
      $mod->setComponent('field_captura', [
        'type' => 'responsive_image',
        'label' => 'above',
        'weight' => 6,
        'settings' => [
          'responsive_image_style' => 'wide',
          'image_link' => '',
          'image_loading' => ['attribute' => 'lazy'],
        ],
      ]);
      $mod->save();
    }
  }

  /**
   * Registration form: contact data only, no language/path/timezone widgets.
   */
  protected function hideRegisterWidgets(): void {
    $register = EntityFormDisplay::load('user.user.register');
    if (!$register) {
      return;
    }
    foreach (['field_nombre_completo', 'field_telefono', 'field_numero_casa'] as $i => $name) {
      $register->setComponent($name, [
        'type' => $name === 'field_telefono' ? 'telephone_default' : 'string_textfield',
        'weight' => $i,
      ]);
    }
    foreach (['language', 'path', 'timezone', 'contact'] as $name) {
      $register->removeComponent($name);
    }
    $register->save();
  }

  /**
   * Residents can download PDFs of minutes and reservation confirmations.
   */
  protected function grantPrintPermissions(): void {
    $perms = [
      'entity print access bundle documento',
      'entity print access bundle reservacion',
    ];
    foreach (['anonymous', 'authenticated', 'residente_titular', 'residente_familiar', 'administracion', 'webmaster', 'mesa_directiva'] as $rid) {
      $this->rolePerms($rid, $perms);
    }
  }

  // -------------------------------------------------------------------------
  // Helpers.
  // -------------------------------------------------------------------------

  protected function ensureStorage(string $entity, string $name, string $type, int $cardinality, array $settings): void {
    if (FieldStorageConfig::loadByName($entity, $name)) {
      return;
    }
    $data = [
      'field_name' => $name,
      'entity_type' => $entity,
      'type' => $type,
      'cardinality' => $cardinality,
      'translatable' => FALSE,
      'settings' => $settings,
    ];
    if ($type === 'image' || $type === 'file') {
      $data['settings'] += ['target_type' => 'file'];
    }
    FieldStorageConfig::create($data)->save();
  }

  protected function ensureInstance(string $entity, string $bundle, string $name, string $label, bool $required = FALSE, array $settings = []): void {
    if ($name === '' || $entity === '' || $bundle === '') {
      throw new \InvalidArgumentException("Campo inválido entity=$entity bundle=$bundle name=$name label=$label");
    }
    if (FieldConfig::loadByName($entity, $bundle, $name)) {
      return;
    }
    try {
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => $entity,
        'bundle' => $bundle,
        'label' => $label,
        'required' => $required,
        'settings' => $settings,
      ])->save();
    }
    catch (\Throwable $e) {
      throw new \RuntimeException("No se pudo crear $entity.$bundle.$name ($label): " . $e->getMessage(), 0, $e);
    }
  }

  protected function ensureParagraphType(string $id, string $label, array $fields): void {
    if (!ParagraphsType::load($id)) {
      ParagraphsType::create(['id' => $id, 'label' => $label])->save();
    }
    foreach ($fields as [$name, $type, $flabel]) {
      $this->ensureStorage('paragraph', $name, $type, 1, []);
      $this->ensureInstance('paragraph', $id, $name, $flabel);
    }
  }

  protected function ensureFormDisplay(string $entity, string $bundle): EntityFormDisplay {
    $id = "$entity.$bundle.default";
    $display = EntityFormDisplay::load($id);
    if (!$display) {
      $display = EntityFormDisplay::create([
        'targetEntityType' => $entity,
        'bundle' => $bundle,
        'mode' => 'default',
        'status' => TRUE,
      ]);
    }
    return $display;
  }

  protected function ensureViewDisplay(string $entity, string $bundle): EntityViewDisplay {
    $id = "$entity.$bundle.default";
    $display = EntityViewDisplay::load($id);
    if (!$display) {
      $display = EntityViewDisplay::create([
        'targetEntityType' => $entity,
        'bundle' => $bundle,
        'mode' => 'default',
        'status' => TRUE,
      ]);
    }
    return $display;
  }

  protected function defaultWidget(string $type): string {
    return match ($type) {
      'boolean' => 'boolean_checkbox',
      'datetime' => 'datetime_default',
      'smartdate' => 'smartdate_default',
      'image' => 'image_image',
      'file' => 'file_generic',
      'entity_reference' => 'entity_reference_autocomplete',
      'entity_reference_revisions' => 'paragraphs',
      'list_string' => 'options_select',
      'text_long' => 'text_textarea',
      'string_long' => 'string_textarea',
      'integer', 'decimal' => 'number',
      'telephone' => 'telephone_default',
      'email' => 'email_default',
      'link' => 'link_default',
      default => 'string_textfield',
    };
  }

  protected function defaultFormatter(string $type): string {
    return match ($type) {
      'boolean' => 'boolean',
      'datetime' => 'datetime_default',
      'smartdate' => 'smartdate_default',
      'image' => 'image',
      'file' => 'file_default',
      'entity_reference', 'entity_reference_revisions' => 'entity_reference_label',
      'list_string' => 'list_default',
      'text_long', 'text_with_summary' => 'text_default',
      'telephone' => 'telephone_link',
      'email' => 'email_mailto',
      'link' => 'link',
      'decimal' => 'number_decimal',
      'integer' => 'number_integer',
      default => 'string',
    };
  }

  protected function ensureWorkflow(string $id, string $label, array $states, string $default, array $bundles): void {
    if ($existing = Workflow::load($id)) {
      $existing->delete();
    }
    $state_config = [
      'draft' => [
        'label' => 'Borrador',
        'published' => FALSE,
        'default_revision' => FALSE,
        'weight' => -10,
      ],
      'published' => [
        'label' => 'Publicado',
        'published' => TRUE,
        'default_revision' => TRUE,
        'weight' => -9,
      ],
    ];
    $weight = 0;
    foreach ($states as $sid => $slabel) {
      $state_config[$sid] = [
        'label' => $slabel,
        'published' => TRUE,
        'default_revision' => TRUE,
        'weight' => $weight++,
      ];
    }
    $transitions = [];
    $keys = array_keys($states);
    $transitions['crear'] = [
      'label' => 'Crear',
      'from' => [],
      'to' => $default,
      'weight' => 0,
    ];
    $w = 1;
    foreach ($keys as $from) {
      foreach ($keys as $to) {
        if ($from === $to) {
          continue;
        }
        $transitions['de_' . $from . '_a_' . $to] = [
          'label' => $states[$to],
          'from' => [$from],
          'to' => $to,
          'weight' => $w++,
        ];
      }
    }
    // Extra named transitions used in permission grants.
    if ($id === 'solicitud_wf') {
      $transitions['a_en_proceso'] = ['label' => 'Poner en proceso', 'from' => ['asignada'], 'to' => 'en_proceso', 'weight' => 90];
      $transitions['a_resuelta'] = ['label' => 'Marcar resuelta', 'from' => ['en_proceso'], 'to' => 'resuelta', 'weight' => 91];
    }
    if ($id === 'reservacion_wf') {
      $transitions['a_cancelada'] = ['label' => 'Cancelar', 'from' => ['solicitada', 'aprobada'], 'to' => 'cancelada', 'weight' => 90];
    }

    Workflow::create([
      'id' => $id,
      'label' => $label,
      'type' => 'content_moderation',
      'type_settings' => [
        'states' => $state_config,
        'transitions' => $transitions,
        'entity_types' => ['node' => $bundles],
        'default_moderation_state' => $default,
      ],
    ])->save();
  }

  protected function termOptions(string $vid): string {
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => $vid]);
    $out = '';
    foreach ($terms as $term) {
      $out .= '    ' . $term->id() . ': "' . str_replace('"', '', $term->label()) . "\"\n";
    }
    return $out;
  }

  protected function ensureWebform(string $id, string $title, string $elements, ?string $node_type, string $action = 'create'): void {
    if (Webform::load($id)) {
      return;
    }
    $webform = Webform::create([
      'id' => $id,
      'title' => $title,
      'status' => TRUE,
      'elements' => $elements,
    ]);
    $webform->save();
    try {
      $handler = \Drupal::service('plugin.manager.webform.handler')->createInstance('provenza_create_node', [
        'id' => 'provenza_create_node',
        'handler_id' => 'crear_seguimiento',
        'label' => 'Crear nodo de seguimiento',
        'status' => 1,
        'weight' => 0,
        'settings' => [
          'node_type' => $node_type ?: '',
          'action' => $action,
        ],
      ]);
      $handler->setHandlerId('crear_seguimiento');
      $webform->addWebformHandler($handler);
    }
    catch (\Throwable $e) {
      \Drupal::logger('provenza_core')->error('Handler webform @id: @m', ['@id' => $id, '@m' => $e->getMessage()]);
    }
  }

  protected function menuLink(string $menu, string $title, string $path, int $weight): void {
    $storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');
    $existing = $storage->loadByProperties(['menu_name' => $menu, 'title' => $title]);
    $link = $existing ? reset($existing) : $storage->create([
      'title' => $title,
      'menu_name' => $menu,
    ]);
    $link->set('title', $title);
    $link->set('link', ['uri' => 'internal:' . $path]);
    $link->set('menu_name', $menu);
    $link->set('weight', $weight);
    $link->save();
  }

  protected function clearMenu(string $menu): void {
    $storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');
    $links = $storage->loadByProperties(['menu_name' => $menu]);
    $storage->delete($links);
  }

  protected function simpleNodeView(string $id, string $title, string $bundle, string $path, array $extra_fields): void {
    if (View::load($id)) {
      return;
    }
    $fields = [
      'title' => [
        'id' => 'title',
        'table' => 'node_field_data',
        'field' => 'title',
        'plugin_id' => 'field',
        'entity_type' => 'node',
        'entity_field' => 'title',
        'label' => 'Título',
        'type' => 'string',
        'settings' => ['link_to_entity' => TRUE],
      ],
    ];
    $filters = [
      'status' => [
        'id' => 'status',
        'table' => 'node_field_data',
        'field' => 'status',
        'plugin_id' => 'boolean',
        'entity_type' => 'node',
        'entity_field' => 'status',
        'value' => '1',
      ],
      'type' => [
        'id' => 'type',
        'table' => 'node_field_data',
        'field' => 'type',
        'plugin_id' => 'bundle',
        'entity_type' => 'node',
        'entity_field' => 'type',
        'value' => [$bundle => $bundle],
      ],
    ];
    if ($id === 'actas_lista') {
      $filters['field_tipo_documento_target_id'] = [
        'id' => 'field_tipo_documento_target_id',
        'table' => 'node__field_tipo_documento',
        'field' => 'field_tipo_documento_target_id',
        'plugin_id' => 'taxonomy_index_tid',
        'operator' => 'or',
        'value' => [],
        'expose' => ['operator_id' => '', 'identifier' => 'tipo'],
      ];
    }
    View::create([
      'id' => $id,
      'label' => $title,
      'module' => 'views',
      'base_table' => 'node_field_data',
      'base_field' => 'nid',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_title' => 'Default',
          'position' => 0,
          'display_options' => [
            'title' => $title,
            'fields' => $fields,
            'filters' => $filters,
            'access' => ['type' => 'perm', 'options' => ['perm' => 'access content']],
            'pager' => ['type' => 'full', 'options' => ['items_per_page' => 20]],
            'style' => ['type' => 'table', 'options' => ['columns' => ['title' => 'title'], 'default' => 'title', 'info' => []]],
            'row' => ['type' => 'fields'],
            'empty' => [
              'area' => [
                'id' => 'area',
                'table' => 'views',
                'field' => 'area',
                'plugin_id' => 'text',
                'content' => ['value' => 'No hay resultados.', 'format' => 'basic_html'],
              ],
            ],
            'sorts' => [
              'created' => [
                'id' => 'created',
                'table' => 'node_field_data',
                'field' => 'created',
                'plugin_id' => 'date',
                'order' => 'DESC',
                'entity_type' => 'node',
                'entity_field' => 'created',
              ],
            ],
          ],
        ],
        'page_1' => [
          'display_plugin' => 'page',
          'id' => 'page_1',
          'display_title' => 'Página',
          'position' => 1,
          'display_options' => [
            'path' => $path,
          ],
        ],
      ],
    ])->save();
  }

  protected function ensurePage(string $title, string $body, string $alias): void {
    $nids = \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('title', $title)->condition('type', 'page')->execute();
    if ($nids) {
      $node = Node::load(reset($nids));
      if ($node) {
        $node->set('body', ['value' => $body, 'format' => 'basic_html']);
        $node->save();
      }
      return;
    }
    $node = Node::create([
      'type' => 'page',
      'title' => $title,
      'body' => ['value' => $body, 'format' => 'basic_html'],
      'status' => 1,
      'uid' => 1,
      'path' => ['alias' => $alias, 'pathauto' => 0],
    ]);
    $node->save();
  }

  protected function placeBlock(string $id, string $plugin, string $region, int $weight, ?string $pages, array $roles = []): void {
    $visibility = [];
    if ($pages) {
      $visibility['request_path'] = [
        'id' => 'request_path',
        'negate' => FALSE,
        'pages' => $pages,
      ];
    }
    if ($roles) {
      $role_map = [];
      foreach ($roles as $rid) {
        $role_map[$rid] = $rid;
      }
      $visibility['user_role'] = [
        'id' => 'user_role',
        'negate' => FALSE,
        'context_mapping' => ['user' => '@user.current_user_context:current_user'],
        'roles' => $role_map,
      ];
    }
    $settings = [
      'id' => $plugin,
      'label' => '',
      'label_display' => FALSE,
      'provider' => str_contains($plugin, 'system_menu_block') ? 'system' : 'provenza_core',
    ];
    if (str_starts_with($plugin, 'system_menu_block:')) {
      $settings += [
        'level' => 1,
        'depth' => 1,
        'expand_all_items' => FALSE,
      ];
    }
    try {
      $block = Block::load($id);
      if (!$block) {
        $block = Block::create([
          'id' => $id,
          'plugin' => $plugin,
          'theme' => 'olivero',
          'region' => $region,
          'weight' => $weight,
          'settings' => $settings,
          'visibility' => $visibility,
        ]);
      }
      else {
        $block->set('region', $region);
        $block->set('weight', $weight);
        $block->set('visibility', $visibility);
        $block->setStatus(TRUE);
      }
      $block->save();
    }
    catch (\Throwable $e) {
      \Drupal::logger('provenza_core')->warning('No se pudo colocar el bloque @id: @m', ['@id' => $id, '@m' => $e->getMessage()]);
    }
  }

  protected function rolePerms(string $rid, array $perms): void {
    $role = Role::load($rid);
    if (!$role) {
      return;
    }
    $valid = array_keys(\Drupal::service('user.permissions')->getPermissions());
    foreach ($perms as $perm) {
      if (in_array($perm, $valid, TRUE)) {
        $role->grantPermission($perm);
      }
    }
    $role->save();
  }

}
