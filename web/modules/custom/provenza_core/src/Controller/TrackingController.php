<?php

namespace Drupal\provenza_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;

/**
 * "My items" lists for residents and maintenance.
 */
class TrackingController extends ControllerBase {

  /**
   * Lists the current user's tracking nodes of a type.
   */
  public function myList(string $type): array {
    $query = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', $type)
      ->sort('created', 'DESC');
    $house = provenza_core_user_house_id($this->currentUser());
    $or = $query->orConditionGroup()
      ->condition('uid', $this->currentUser()->id());
    if ($house) {
      $or->condition('field_domicilio', $house);
    }
    $query->condition($or);
    $nids = $query->execute();
    return $this->table($type, Node::loadMultiple($nids));
  }

  /**
   * Requests assigned to the current maintenance user.
   */
  public function assigned(): array {
    $nids = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'solicitud')
      ->condition('field_asignado', $this->currentUser()->id())
      ->sort('created', 'DESC')
      ->execute();
    return $this->table('solicitud', Node::loadMultiple($nids), TRUE);
  }

  /**
   * Builds a tracking table.
   */
  protected function table(string $type, array $nodes, bool $show_assign = FALSE): array {
    $header = ['Folio', 'Título', 'Estado', 'Fecha', 'Acciones'];
    $rows = [];
    foreach ($nodes as $node) {
      $folio = $node->hasField('field_folio') ? $node->get('field_folio')->value : $node->id();
      $state = $node->hasField('moderation_state') && !$node->get('moderation_state')->isEmpty()
        ? $node->get('moderation_state')->value
        : ($node->hasField('field_estado_visita') ? $node->get('field_estado_visita')->value : '—');
      $actions = [Link::fromTextAndUrl('Ver', $node->toUrl())->toString()];
      if ($type === 'visita') {
        $actions[] = Link::createFromRoute('Pase QR', 'provenza_core.qr', ['node' => $node->id()])->toString();
      }
      if ($type === 'solicitud' && $node->get('moderation_state')->value === 'resuelta' && (int) $node->getOwnerId() === (int) $this->currentUser()->id()) {
        $actions[] = ['#markup' => '<a href="/form/calificar-servicio">Calificar</a>'];
      }
      $rows[] = [
        ['data' => ['#markup' => '<span class="provenza-folio">' . $folio . '</span>']],
        Link::fromTextAndUrl($node->label(), $node->toUrl()),
        $this->stateLabel($state),
        \Drupal::service('date.formatter')->format($node->getCreatedTime(), 'short'),
        ['data' => ['#markup' => implode(' · ', array_map(static function ($a): string {
          if (is_array($a)) {
            return (string) \Drupal::service('renderer')->render($a);
          }
          return (string) $a;
        }, $actions))]],
      ];
    }
    $create = match ($type) {
      'solicitud' => ['/form/solicitud-servicio', 'Nueva solicitud'],
      'queja' => ['/form/queja-sugerencia', 'Nueva queja o sugerencia'],
      'reservacion' => ['/form/reservar-salon', 'Solicitar reservación'],
      'visita' => ['/form/registrar-visita', 'Registrar visita'],
      default => NULL,
    };
    $build = [];
    if ($create) {
      $build['add'] = [
        '#markup' => '<p><a class="button button--primary" href="' . $create[0] . '">' . $create[1] . '</a></p>',
      ];
    }
    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => 'No hay registros todavía.',
      '#attributes' => ['class' => ['provenza-table']],
    ];
    return $build;
  }

  protected function stateLabel(string $state): string {
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
      'programada' => 'Programada',
      'en_caseta' => 'En caseta / dentro',
      'salio' => 'Salió',
      'vencida' => 'Vencida',
    ];
    return $map[$state] ?? $state;
  }

}
