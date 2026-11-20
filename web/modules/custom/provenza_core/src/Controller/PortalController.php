<?php

namespace Drupal\provenza_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\search_api\Entity\Index;
use Symfony\Component\HttpFoundation\Request;

/**
 * Public and resident portal pages.
 */
class PortalController extends ControllerBase {

  /**
   * Front page.
   */
  public function home(): array {
    $account = $this->currentUser();
    $is_resident = (bool) array_intersect($account->getRoles(), [
      'residente_titular', 'residente_familiar', 'administracion', 'webmaster',
    ]);
    $event_nids = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'evento')
      ->condition('status', 1)
      ->condition('field_fecha_evento', time(), '>=')
      ->sort('field_fecha_evento', 'ASC')
      ->range(0, 5)
      ->execute();
    if (!$event_nids) {
      $event_nids = $this->entityTypeManager()->getStorage('node')->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'evento')
        ->condition('status', 1)
        ->sort('field_fecha_evento', 'ASC')
        ->range(0, 5)
        ->execute();
    }
    $events = [];
    foreach (Node::loadMultiple($event_nids) as $node) {
      $when = '';
      if ($node->hasField('field_fecha_evento') && !$node->get('field_fecha_evento')->isEmpty()) {
        $when = \Drupal::service('date.formatter')->format((int) $node->get('field_fecha_evento')->value, 'custom', 'd/m/Y H:i', 'America/Mexico_City');
      }
      $events[] = [
        'title' => $when ? $when . ' · ' . $node->label() : $node->label(),
        'url' => $node->toUrl()->toString(),
      ];
    }

    $login = [];
    if ($account->isAnonymous()) {
      $login = $this->formBuilder()->getForm(\Drupal\user\Form\UserLoginForm::class);
      $login['#action'] = Url::fromRoute('user.login', [], [
        'query' => ['destination' => '/inicio'],
      ])->toString();
    }

    return [
      '#theme' => 'provenza_home',
      '#urgent' => [],
      '#tiles' => $is_resident ? [] : [
        ['title' => 'Ver tarifas', 'url' => '/comunidad/cuotas-y-tarifas'],
        ['title' => 'Instalaciones', 'url' => '/instalaciones'],
        ['title' => 'Avisos', 'url' => '/comunidad/avisos'],
        ['title' => 'Registrarme', 'url' => '/user/register'],
      ],
      '#events' => $events,
      '#login' => $login,
      '#is_resident' => $is_resident,
      '#attached' => ['library' => ['provenza_core/portal']],
      '#cache' => [
        'contexts' => ['user.roles'],
        'tags' => ['node_list:aviso', 'node_list:evento'],
        'max-age' => 0,
      ],
    ];
  }

  /**
   * HTML sitemap excluding the tutorial area.
   */
  public function sitemap(): array {
    $groups = [
      'Principal' => [
        ['Inicio', '/inicio'],
        ['Solicitudes de servicio', '/solicitudes'],
        ['Quejas y sugerencias', '/quejas'],
        ['Instalaciones', '/instalaciones'],
        ['Seguridad y acceso', '/seguridad'],
        ['Comunidad', '/comunidad'],
        ['Contacto', '/contacto'],
      ],
      'Comunidad' => [
        ['Avisos', '/comunidad/avisos'],
        ['Calendario de eventos', '/comunidad/calendario'],
        ['Cuotas y tarifas', '/comunidad/cuotas-y-tarifas'],
        ['Documentos y reglamento', '/comunidad/documentos'],
        ['Actas de asamblea', '/comunidad/actas'],
        ['Preguntas frecuentes', '/comunidad/preguntas-frecuentes'],
        ['Directorio', '/comunidad/directorio'],
      ],
      'Legal' => [
        ['Aviso de privacidad', '/aviso-de-privacidad'],
        ['Términos de uso', '/terminos-de-uso'],
        ['Accesibilidad', '/accesibilidad'],
      ],
    ];
    $items = [];
    foreach ($groups as $label => $links) {
      $children = [];
      foreach ($links as [$title, $path]) {
        $children[] = [
          '#type' => 'link',
          '#title' => $title,
          '#url' => Url::fromUserInput($path),
        ];
      }
      $items[] = [
        ['#markup' => '<strong>' . $label . '</strong>'],
        ['#theme' => 'item_list', '#items' => $children],
      ];
    }
    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#title' => NULL,
    ];
  }

  /**
   * Role-aware search that excludes tutorial content.
   */
  public function search(Request $request): array {
    $q = trim((string) $request->query->get('q', ''));
    $items = [];
    if (mb_strlen($q) >= 2) {
      $items = $this->searchApiHits($q);
      if (!$items) {
        $query = $this->entityTypeManager()->getStorage('node')->getQuery()
          ->accessCheck(TRUE)
          ->condition('status', 1)
          ->condition('title', $q, 'CONTAINS')
          ->condition('type', 'modulo_documentado', '<>')
          ->range(0, 30)
          ->sort('created', 'DESC');
        $items = $this->searchHitItems(Node::loadMultiple($query->execute()));
      }
    }
    return [
      'form' => [
        '#markup' => '<form method="get" class="provenza-search-form" action="/buscar"><label for="q">Buscar</label><input id="q" name="q" type="search" value="' . htmlspecialchars($q) . '" /><button type="submit">Buscar</button></form>',
      ],
      'results' => $items ? ['#theme' => 'item_list', '#items' => $items] : [
        '#markup' => $q ? '<p>No se encontraron resultados.</p>' : '<p>Escribe al menos dos caracteres.</p>',
      ],
      '#cache' => [
        'contexts' => ['url.query_args:q', 'user.roles'],
        'tags' => ['node_list'],
      ],
    ];
  }

  /**
   * Search API hits, excluding the tutorial bundle at index level.
   */
  protected function searchApiHits(string $q): array {
    if (!class_exists(Index::class)) {
      return [];
    }
    $index = Index::load('contenido');
    if (!$index || !$index->status()) {
      return [];
    }
    try {
      $query = $index->query();
      $query->keys($q);
      $query->range(0, 30);
      $nodes = [];
      foreach ($query->execute()->getResultItems() as $item) {
        $object = $item->getOriginalObject();
        $entity = $object ? $object->getValue() : NULL;
        if ($entity instanceof Node && $entity->bundle() !== 'modulo_documentado') {
          $nodes[$entity->id()] = $entity;
        }
      }
      return $this->searchHitItems($nodes);
    }
    catch (\Throwable $e) {
      return [];
    }
  }

  /**
   * @param \Drupal\node\NodeInterface[] $nodes
   */
  protected function searchHitItems(array $nodes): array {
    $items = [];
    foreach ($nodes as $node) {
      $items[] = [
        '#type' => 'link',
        '#title' => $node->label(),
        '#url' => $node->toUrl(),
        '#prefix' => '<div class="provenza-search-hit"><span class="provenza-badge">' . $node->type->entity->label() . '</span> ',
        '#suffix' => '</div>',
      ];
    }
    return $items;
  }

  /**
   * Complaint form with the confidential option already checked.
   */
  public function confidentialComplaint(): array {
    return [
      'intro' => [
        '#markup' => '<p>Tu identidad solo será visible para Administración. El seguimiento queda en <a href="/quejas/mis-quejas">Mis quejas</a>.</p>',
      ],
      'form' => [
        '#type' => 'webform',
        '#webform' => 'queja_sugerencia',
        '#default_data' => ['confidencial' => 1],
      ],
    ];
  }

  /**
   * Public listing of a content type.
   */
  public function listing(string $bundle): array {
    $query = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', $bundle)
      ->condition('status', 1)
      ->sort('title');
    $route = \Drupal::routeMatch()->getRouteName();
    if ($route === 'provenza_core.actas') {
      $tid = NULL;
      $terms = $this->entityTypeManager()->getStorage('taxonomy_term')->loadByProperties([
        'vid' => 'tipos_documento',
        'name' => 'Acta de asamblea',
      ]);
      if ($terms) {
        $query->condition('field_tipo_documento', reset($terms)->id());
      }
    }
    $nodes = Node::loadMultiple($query->execute());
    $items = [];
    foreach ($nodes as $node) {
      $extra = '';
      if ($bundle === 'tarifa' && $node->hasField('field_monto')) {
        $extra = ' — $' . number_format((float) $node->get('field_monto')->value, 0, '.', ',');
      }
      if ($bundle === 'directorio' && $node->hasField('field_telefono') && !$node->get('field_telefono')->isEmpty()) {
        $tel = $node->get('field_telefono')->value;
        $extra = ' — <a href="tel:' . $tel . '">' . $tel . '</a>';
      }
      $items[] = [
        '#markup' => '<a href="' . $node->toUrl()->toString() . '">' . $node->label() . '</a>' . $extra,
      ];
    }
    return [
      'list' => [
        '#theme' => 'item_list',
        '#items' => $items,
        '#empty' => 'No hay contenidos todavía.',
        '#attributes' => ['class' => ['provenza-listing']],
      ],
    ];
  }

  /**
   * Title callback for generic listings.
   */
  public function listingTitle(string $bundle): string {
    $labels = [
      'tarifa' => 'Cuotas y tarifas',
      'aviso' => 'Avisos',
      'faq' => 'Preguntas frecuentes',
      'documento' => 'Documentos',
      'directorio' => 'Directorio',
      'instalacion' => 'Instalaciones',
      'evento' => 'Calendario de eventos',
      'modulo_documentado' => 'Módulos documentados',
    ];
    return $labels[$bundle] ?? $bundle;
  }

  /**
   * Ballroom calendar page.
   */
  public function calendar(Request $request): array {
    return $this->calendarBuild(
      (int) $request->query->get('y', date('Y')),
      (int) $request->query->get('m', date('n')),
    );
  }

  /**
   * Month grid of booked / free / blocked dates.
   */
  public function calendarBuild(?int $year = NULL, ?int $month = NULL): array {
    $year = $year ?: (int) date('Y');
    $month = $month ?: (int) date('n');
    $start = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    $end = $start->modify('last day of this month');
    $booked = $this->bookedDays($start, $end);

    $days = [];
    $pad = (int) $start->format('N') - 1;
    for ($i = 0; $i < $pad; $i++) {
      $days[] = ['empty' => TRUE];
    }
    for ($d = 1; $d <= (int) $end->format('j'); $d++) {
      $key = sprintf('%04d-%02d-%02d', $year, $month, $d);
      $status = $booked[$key] ?? 'libre';
      $days[] = [
        'date' => $d,
        'status' => $status,
        'label' => $status === 'reservado' ? 'Reservado' : ($status === 'bloqueado' ? 'Bloqueado' : 'Libre'),
      ];
    }
    $prev = $start->modify('-1 month');
    $next = $start->modify('+1 month');
    return [
      '#theme' => 'provenza_calendar',
      '#year' => $year,
      '#month' => $this->monthName($month) . ' ' . $year,
      '#days' => $days,
      '#prev' => '/instalaciones/salon-de-fiestas/calendario?y=' . $prev->format('Y') . '&m=' . $prev->format('n'),
      '#next' => '/instalaciones/salon-de-fiestas/calendario?y=' . $next->format('Y') . '&m=' . $next->format('n'),
      '#legend' => ['libre' => 'Libre', 'reservado' => 'Reservado', 'bloqueado' => 'Bloqueado'],
      '#cache' => ['tags' => ['node_list:reservacion'], 'contexts' => ['url.query_args']],
    ];
  }

  /**
   * Maps dates to reservation status.
   */
  protected function bookedDays(\DateTimeImmutable $start, \DateTimeImmutable $end): array {
    $nids = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'reservacion')
      ->execute();
    $out = [];
    $active = ['solicitada', 'aprobada', 'realizada'];
    foreach (Node::loadMultiple($nids) as $node) {
      $state = $node->hasField('moderation_state') ? $node->get('moderation_state')->value : '';
      if (!in_array($state, $active, TRUE) || $node->get('field_fecha_inicio')->isEmpty()) {
        continue;
      }
      $from = substr($node->get('field_fecha_inicio')->value, 0, 10);
      $to = $node->get('field_fecha_fin')->isEmpty() ? $from : substr($node->get('field_fecha_fin')->value, 0, 10);
      $state = $node->get('moderation_state')->value === 'solicitada' ? 'bloqueado' : 'reservado';
      $cursor = new \DateTimeImmutable($from);
      $last = new \DateTimeImmutable($to);
      while ($cursor <= $last) {
        $key = $cursor->format('Y-m-d');
        if ($key >= $start->format('Y-m-d') && $key <= $end->format('Y-m-d')) {
          $out[$key] = $state;
        }
        $cursor = $cursor->modify('+1 day');
      }
    }
    return $out;
  }

  protected function monthName(int $m): string {
    $names = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    return $names[$m] ?? (string) $m;
  }

}
