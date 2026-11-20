<?php

namespace Drupal\provenza_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;

/**
 * Panel del colono: un solo lugar para todos los trámites de la cuenta.
 */
class PanelController extends ControllerBase {

  /**
   * Etiquetas de estado en español.
   */
  public static function stateLabel(string $state): string {
    $map = [
      'recibida' => 'Recibida',
      'en_revision' => 'En revisión',
      'asignada' => 'Asignada',
      'en_proceso' => 'En proceso',
      'resuelta' => 'Resuelta',
      'cerrada' => 'Cerrada',
      'rechazada' => 'Rechazada',
      'en_investigacion' => 'En investigación',
      'accion_tomada' => 'Acción tomada',
      'solicitada' => 'Solicitada',
      'aprobada' => 'Aprobada',
      'realizada' => 'Realizada',
      'cancelada' => 'Cancelada',
      'esperada' => 'Esperada',
      'dentro' => 'Dentro',
      'salio' => 'Salió',
      'vencida' => 'Vencida',
    ];
    return $map[$state] ?? ucfirst(str_replace('_', ' ', $state));
  }

  /**
   * Accesos del panel, también usados por el tema para la barra lateral.
   */
  public static function navigation(): array {
    $account = \Drupal::currentUser();
    $roles = $account->getRoles();
    $items = [
      ['provenza_core.panel', 'Resumen', 'casa'],
      ['provenza_core.mis_solicitudes', 'Solicitudes', 'solicitud'],
      ['provenza_core.mis_reservaciones', 'Reservaciones', 'calendario'],
      ['provenza_core.mis_autorizaciones', 'Visitas y pases', 'qr'],
      ['provenza_core.mis_quejas', 'Quejas', 'queja'],
      ['provenza_core.mis_vehiculos', 'Vehículos y tags', 'auto'],
      ['provenza_core.mi_domicilio', 'Mi domicilio', 'llave'],
      ['provenza_core.notificaciones', 'Notificaciones', 'correo'],
      ['provenza_core.seguridad', 'Seguridad de la cuenta', 'escudo'],
    ];
    if (in_array('mantenimiento', $roles, TRUE)) {
      $items[] = ['provenza_core.mis_asignaciones', 'Mis asignaciones', 'solicitud'];
    }
    if (in_array('caseta', $roles, TRUE) || in_array('administracion', $roles, TRUE)) {
      $items[] = ['provenza_core.caseta', 'Vista de caseta', 'escudo'];
    }
    if ($account->hasPermission('access provenza admin')) {
      $items[] = ['provenza_core.admin', 'Administración', 'edificio'];
    }
    $current = \Drupal::routeMatch()->getRouteName();
    $out = [];
    foreach ($items as [$route, $label, $icon]) {
      $url = Url::fromRoute($route);
      if (!$url->access($account)) {
        continue;
      }
      $out[] = [
        'titulo' => $label,
        'url' => $url->toString(),
        'icono' => $icon,
        'activo' => $current === $route,
      ];
    }
    return $out;
  }

  /**
   * Acciones rápidas (trámites nuevos).
   */
  public static function actions(): array {
    return [
      ['titulo' => 'Reportar una falla', 'texto' => 'Alumbrado, jardines, alberca y áreas comunes.', 'url' => '/form/solicitud-servicio', 'icono' => 'foco'],
      ['titulo' => 'Reservar el salón', 'texto' => 'Revisa fechas libres y envía tu solicitud.', 'url' => '/form/reservar-salon', 'icono' => 'fiesta'],
      ['titulo' => 'Registrar una visita', 'texto' => 'Genera un pase QR para tu invitado.', 'url' => '/form/registrar-visita', 'icono' => 'qr'],
      ['titulo' => 'Queja o sugerencia', 'texto' => 'Puede ser confidencial.', 'url' => '/form/queja-sugerencia', 'icono' => 'queja'],
      ['titulo' => 'Recolección especial', 'texto' => 'Muebles, poda o cascajo.', 'url' => '/form/recoleccion-especial', 'icono' => 'camion'],
      ['titulo' => 'Reportar un incidente', 'texto' => 'Avisa a caseta de algo fuera de lo normal.', 'url' => '/form/reportar-incidente', 'icono' => 'alerta'],
    ];
  }

  /**
   * Resumen del panel.
   */
  public function summary(): array {
    $account = User::load($this->currentUser()->id());
    $nombre = $account->hasField('field_nombre_completo') && !$account->get('field_nombre_completo')->isEmpty()
      ? (string) $account->get('field_nombre_completo')->value
      : $account->getDisplayName();
    $house_id = provenza_core_user_house_id($account);
    $house = $house_id ? Node::load($house_id) : NULL;
    $storage = $this->entityTypeManager()->getStorage('node');

    // Trámites recientes de la cuenta o de su casa.
    $query = $storage->getQuery()->accessCheck(TRUE)
      ->condition('type', ['solicitud', 'queja', 'reservacion', 'visita'], 'IN')
      ->sort('changed', 'DESC')
      ->range(0, 7);
    $or = $query->orConditionGroup()->condition('uid', $account->id());
    if ($house_id) {
      $or->condition('field_domicilio', $house_id);
    }
    $query->condition($or);
    $tipos = ['solicitud' => 'Solicitud', 'queja' => 'Queja', 'reservacion' => 'Reservación', 'visita' => 'Visita'];
    $recientes = [];
    foreach (Node::loadMultiple($query->execute()) as $node) {
      $state = $this->tramiteState($node);
      $recientes[] = [
        'titulo' => $node->label(),
        'url' => $node->toUrl()->toString(),
        'tipo' => $tipos[$node->bundle()] ?? $node->bundle(),
        'folio' => $node->hasField('field_folio') ? (string) $node->get('field_folio')->value : '',
        'estado' => $state,
        'estado_texto' => self::stateLabel($state),
        'fecha' => \Drupal::service('date.formatter')->format($node->getChangedTime(), 'custom', 'j M', 'America/Mexico_City'),
      ];
    }

    // Próxima reservación activa.
    $reservacion = NULL;
    $nids = $storage->getQuery()->accessCheck(TRUE)
      ->condition('type', 'reservacion')
      ->condition('field_fecha_inicio', gmdate('Y-m-d\TH:i:s'), '>=')
      ->sort('field_fecha_inicio', 'ASC')
      ->range(0, 5)
      ->execute();
    foreach (Node::loadMultiple($nids) as $node) {
      $state = $this->tramiteState($node);
      if (in_array($state, ['solicitada', 'aprobada'], TRUE) && ($node->getOwnerId() == $account->id() || ($house_id && $node->get('field_domicilio')->target_id == $house_id))) {
        $ts = strtotime($node->get('field_fecha_inicio')->value . ' UTC');
        $reservacion = [
          'titulo' => $node->get('field_instalacion')->entity?->label() ?? $node->label(),
          'url' => $node->toUrl()->toString(),
          'dia' => \Drupal::service('date.formatter')->format($ts, 'custom', 'j', 'America/Mexico_City'),
          'mes' => \Drupal::service('date.formatter')->format($ts, 'custom', 'F', 'America/Mexico_City'),
          'hora' => \Drupal::service('date.formatter')->format($ts, 'custom', 'l, H:i', 'America/Mexico_City') . ' h',
          'estado' => $state,
          'estado_texto' => self::stateLabel($state),
          'invitados' => (int) $node->get('field_invitados')->value,
        ];
        break;
      }
    }

    // Pases de visita vigentes.
    $pases = [];
    $nids = $storage->getQuery()->accessCheck(TRUE)
      ->condition('type', 'visita')
      ->condition('field_ventana_fin', gmdate('Y-m-d\TH:i:s'), '>=')
      ->sort('field_ventana_inicio', 'ASC')
      ->range(0, 3)
      ->execute();
    foreach (Node::loadMultiple($nids) as $node) {
      $pases[] = [
        'nombre' => (string) $node->get('field_nombre_visita')->value,
        'ventana' => provenza_core_format_datetime((string) $node->get('field_ventana_inicio')->value),
        'url' => Url::fromRoute('provenza_core.qr', ['node' => $node->id()])->toString(),
      ];
    }

    // Avisos recientes.
    $avisos = [];
    $nids = $storage->getQuery()->accessCheck(TRUE)
      ->condition('type', 'aviso')->condition('status', 1)
      ->sort('created', 'DESC')->range(0, 3)->execute();
    foreach (Node::loadMultiple($nids) as $node) {
      $avisos[] = [
        'titulo' => $node->label(),
        'url' => $node->toUrl()->toString(),
        'urgente' => (bool) $node->get('field_urgente')->value,
      ];
    }

    $hora = (int) (new \DateTime('now', new \DateTimeZone('America/Mexico_City')))->format('G');
    $saludo = $hora < 12 ? 'Buenos días' : ($hora < 19 ? 'Buenas tardes' : 'Buenas noches');

    return [
      '#theme' => 'provenza_panel',
      '#saludo' => $saludo,
      '#nombre' => strtok($nombre, ' '),
      '#casa' => $house ? $house->label() : '',
      '#acciones' => self::actions(),
      '#recientes' => $recientes,
      '#reservacion' => $reservacion,
      '#pases' => $pases,
      '#avisos' => $avisos,
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['node_list', 'user:' . $account->id()],
        'max-age' => 300,
      ],
    ];
  }

  /**
   * Estado de un trámite (moderación o estado de visita).
   */
  protected function tramiteState(NodeInterface $node): string {
    if ($node->hasField('moderation_state') && !$node->get('moderation_state')->isEmpty()) {
      return (string) $node->get('moderation_state')->value;
    }
    if ($node->hasField('field_estado_visita') && !$node->get('field_estado_visita')->isEmpty()) {
      return (string) $node->get('field_estado_visita')->value;
    }
    return 'recibida';
  }

}
