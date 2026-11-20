<?php

namespace Drupal\provenza_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff back office.
 */
class AdminController extends ControllerBase {

  /**
   * Admin landing.
   */
  public function panel(): array {
    $links = [
      ['Bandeja unificada', '/admin/provenza/bandeja'],
      ['Residentes y domicilios', '/admin/provenza/residentes'],
      ['Reportes y tableros', '/admin/provenza/reportes'],
      ['Auditoría', '/admin/provenza/auditoria'],
      ['Plantillas de correo', '/admin/provenza/plantillas-correo'],
      ['Avisos', '/comunidad/avisos'],
      ['Tarifas', '/comunidad/cuotas-y-tarifas'],
      ['Módulos documentados', '/asi-lo-construimos/modulos'],
    ];
    $items = [];
    foreach ($links as [$t, $p]) {
      $items[] = ['#markup' => '<a class="provenza-tile" href="' . $p . '">' . $t . '</a>'];
    }
    return [
      'tiles' => [
        '#prefix' => '<div class="provenza-tiles">',
        '#suffix' => '</div>',
        'items' => $items,
      ],
    ];
  }

  /**
   * Unified queue.
   */
  public function queue(Request $request): array {
    $type = $request->query->get('tipo', 'all');
    $state = $request->query->get('estado', '');
    $types = ['solicitud', 'queja', 'reservacion', 'incidente'];
    $query = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->sort('created', 'DESC')
      ->range(0, 100);
    if ($type !== 'all' && in_array($type, $types, TRUE)) {
      $query->condition('type', $type);
    }
    else {
      $query->condition('type', $types, 'IN');
    }
    $nodes = Node::loadMultiple($query->execute());
    if ($state) {
      $nodes = array_filter($nodes, fn ($n) => $n->hasField('moderation_state') && $n->get('moderation_state')->value === $state);
    }
    $staff = $this->maintenanceUsers();
    $rows = [];
    foreach ($nodes as $node) {
      $folio = $node->hasField('field_folio') ? $node->get('field_folio')->value : $node->id();
      $assign = '';
      if ($node->bundle() === 'solicitud') {
        foreach ($staff as $uid => $name) {
          $url = Url::fromRoute('provenza_core.assign', ['node' => $node->id(), 'user' => $uid], [
            'query' => ['token' => \Drupal::csrfToken()->get('provenza_core.assign/' . $node->id() . '/' . $uid)],
          ])->toString();
          $assign .= '<a href="' . $url . '">' . $name . '</a> ';
        }
      }
      $states = $this->nextStates($node);
      $state_links = '';
      foreach ($states as $sid => $slabel) {
        $url = Url::fromRoute('provenza_core.transition', ['node' => $node->id(), 'state' => $sid], [
          'query' => ['token' => \Drupal::csrfToken()->get('provenza_core.transition/' . $node->id() . '/' . $sid)],
        ])->toString();
        $state_links .= '<a href="' . $url . '">' . $slabel . '</a> ';
      }
      $rows[] = [
        $folio,
        $node->type->entity->label(),
        Link::fromTextAndUrl($node->label(), $node->toUrl()),
        $node->hasField('moderation_state') ? $node->get('moderation_state')->value : 'Sin dato',
        \Drupal::service('date.formatter')->format($node->getCreatedTime(), 'short'),
        ['data' => ['#markup' => $assign . $state_links]],
      ];
    }

    return [
      'filters' => [
        '#markup' => '<form method="get" class="provenza-filters"><label>Tipo <select name="tipo"><option value="all">Todos</option><option value="solicitud">Solicitudes</option><option value="queja">Quejas</option><option value="reservacion">Reservaciones</option><option value="incidente">Incidentes</option></select></label> <label>Estado <input name="estado" value="' . htmlspecialchars((string) $state) . '" /></label> <button type="submit">Filtrar</button> <a href="/admin/provenza/bandeja/exportar">Exportar CSV</a></form>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => ['Folio', 'Tipo', 'Título', 'Estado', 'Fecha', 'Acciones'],
        '#rows' => $rows,
        '#empty' => 'No hay elementos.',
        '#attributes' => ['class' => ['provenza-table']],
      ],
    ];
  }

  /**
   * CSV export of the queue.
   */
  public function export(): Response {
    $nids = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', ['solicitud', 'queja', 'reservacion', 'incidente'], 'IN')
      ->sort('created', 'DESC')
      ->execute();
    $fh = fopen('php://temp', 'r+');
    fputcsv($fh, ['folio', 'tipo', 'titulo', 'estado', 'fecha']);
    foreach (Node::loadMultiple($nids) as $node) {
      fputcsv($fh, [
        $node->hasField('field_folio') ? $node->get('field_folio')->value : $node->id(),
        $node->bundle(),
        $node->label(),
        $node->hasField('moderation_state') ? $node->get('moderation_state')->value : '',
        date('d/m/Y H:i', $node->getCreatedTime()),
      ]);
    }
    rewind($fh);
    $csv = stream_get_contents($fh);
    return new Response($csv, 200, [
      'Content-Type' => 'text/csv; charset=UTF-8',
      'Content-Disposition' => 'attachment; filename="bandeja-provenza.csv"',
    ]);
  }

  /**
   * Resident and house management.
   */
  public function residents(): array {
    $pending = $this->entityTypeManager()->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 0)
      ->condition('uid', 0, '<>')
      ->execute();
    $rows = [];
    foreach (User::loadMultiple($pending) as $user) {
      $approve = Url::fromRoute('provenza_core.approve_user', ['user' => $user->id()]);
      $reject = Url::fromRoute('provenza_core.reject_user', ['user' => $user->id()], [
        'query' => ['token' => \Drupal::csrfToken()->get('provenza_core.reject_user/' . $user->id())],
      ]);
      $rows[] = [
        $user->getDisplayName(),
        $user->getEmail(),
        $user->hasField('field_numero_casa') ? $user->get('field_numero_casa')->value : '',
        ['data' => ['#markup' => Link::fromTextAndUrl('Aprobar y vincular', $approve)->toString() . ' ' . Link::fromTextAndUrl('Rechazar', $reject)->toString()]],
      ];
    }
    $houses = Node::loadMultiple($this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(TRUE)->condition('type', 'domicilio')->sort('title')->execute());
    $hrows = [];
    foreach ($houses as $house) {
      $status = $house->get('field_estado_domicilio')->value;
      $status_label = match ($status) {
        'activo' => 'Activo',
        'cambio_titular' => 'Cambio de titular',
        'desactivado' => 'Desactivado',
        default => (string) $status,
      };
      $edit = Url::fromRoute('entity.node.edit_form', ['node' => $house->id()]);
      $actions = Link::fromTextAndUrl('Editar', $edit)->toString();
      if ($status !== 'desactivado') {
        $deactivate = Url::fromRoute('provenza_core.deactivate_house', ['node' => $house->id()], [
          'query' => ['token' => \Drupal::csrfToken()->get('provenza_core.deactivate_house/' . $house->id())],
        ]);
        $actions .= ' ' . Link::fromTextAndUrl('Desactivar', $deactivate)->toString();
      }
      $hrows[] = [
        $house->label(),
        $status_label,
        $house->get('field_titular')->entity?->label() ?? 'Sin titular',
        ['data' => ['#markup' => $actions]],
      ];
    }
    return [
      'add' => [
        '#markup' => '<p><a class="button button--primary" href="/node/add/domicilio">Nuevo domicilio</a></p>',
      ],
      'pending' => [
        '#type' => 'table',
        '#caption' => 'Registros por aprobar',
        '#header' => ['Nombre', 'Correo', 'Casa', 'Acciones'],
        '#rows' => $rows,
        '#empty' => 'No hay registros pendientes.',
      ],
      'houses' => [
        '#type' => 'table',
        '#caption' => 'Domicilios',
        '#header' => ['Domicilio', 'Estado', 'Titular', 'Acciones'],
        '#rows' => $hrows,
      ],
    ];
  }

  /**
   * Deactivates a house, keeping history and blocking linked accounts.
   */
  public function deactivateHouse(NodeInterface $node): RedirectResponse {
    if ($node->bundle() !== 'domicilio') {
      throw $this->createNotFoundException();
    }
    \Drupal::service('plugin.manager.action')->createInstance('provenza_deactivate_house')->execute($node);
    $this->messenger()->addStatus('Domicilio desactivado. Se conservó el historial y se bloquearon las cuentas ligadas.');
    return new RedirectResponse('/admin/provenza/residentes');
  }

  /**
   * Rejects (blocks) a registration.
   */
  public function rejectUser(UserInterface $user): RedirectResponse {
    $user->block();
    $user->save();
    \Drupal::service('provenza_core.audit')->log('Registro rechazado uid @uid', ['@uid' => $user->id()]);
    $this->messenger()->addStatus('Registro rechazado.');
    return new RedirectResponse('/admin/provenza/residentes');
  }

  /**
   * Assigns a request.
   */
  public function assign(NodeInterface $node, UserInterface $user): RedirectResponse {
    if ($node->hasField('field_asignado')) {
      $node->set('field_asignado', $user->id());
      if ($node->hasField('moderation_state')) {
        $node->set('moderation_state', 'asignada');
      }
      $node->save();
      \Drupal::service('provenza_core.mail_templates')->send('asignacion', $user, $node);
      \Drupal::service('provenza_core.audit')->log('Asignó @folio a @uid', [
        '@folio' => $node->get('field_folio')->value,
        '@uid' => $user->id(),
      ]);
      $this->messenger()->addStatus('Solicitud asignada.');
    }
    return new RedirectResponse('/admin/provenza/bandeja');
  }

  /**
   * Changes moderation state.
   */
  public function transition(NodeInterface $node, string $state): RedirectResponse {
    if ($node->hasField('moderation_state')) {
      $node->set('moderation_state', $state);
      $node->save();
      \Drupal::service('provenza_core.audit')->log('Estado de @folio → @state', [
        '@folio' => $node->hasField('field_folio') ? $node->get('field_folio')->value : $node->id(),
        '@state' => $state,
      ]);
      $this->messenger()->addStatus('Estado actualizado.');
    }
    return new RedirectResponse('/admin/provenza/bandeja');
  }

  /**
   * Dashboards.
   */
  public function reports(): array {
    $open = $this->countByState('solicitud', ['recibida', 'en_revision', 'asignada', 'en_proceso']);
    $incidents = $this->countBundle('incidente');
    $ratings = $this->avgRating();
    $open_by_type = [
      $this->countByState('solicitud', ['recibida', 'en_revision', 'asignada', 'en_proceso']),
      $this->countByState('queja', ['recibida', 'en_investigacion', 'accion_tomada']),
      $this->countByState('reservacion', ['solicitada', 'aprobada']),
      $this->countOpenIncidents(),
    ];
    [$month_labels, $month_data] = $this->incidentsByMonth();
    [$rating_labels, $rating_data] = $this->ratingDistribution();
    return [
      'kpis' => [
        '#markup' => '<div class="provenza-kpis"><div><strong>' . $open . '</strong><span>Solicitudes abiertas</span></div><div><strong>' . $incidents . '</strong><span>Incidentes registrados</span></div><div><strong>' . $ratings . '</strong><span>Satisfacción promedio</span></div></div>',
      ],
      'open_chart' => [
        '#type' => 'chart',
        '#chart_type' => 'column',
        '#title' => 'Expedientes abiertos',
        '#chart_library' => 'chartjs',
        'series' => [
          '#type' => 'chart_data',
          '#title' => 'Abiertos',
          '#data' => $open_by_type,
        ],
        'xaxis' => [
          '#type' => 'chart_xaxis',
          '#labels' => ['Solicitudes', 'Quejas', 'Reservaciones', 'Incidentes'],
        ],
      ],
      'incidents_chart' => [
        '#type' => 'chart',
        '#chart_type' => 'line',
        '#title' => 'Incidentes por mes',
        '#chart_library' => 'chartjs',
        'series' => [
          '#type' => 'chart_data',
          '#title' => 'Incidentes',
          '#data' => $month_data,
        ],
        'xaxis' => [
          '#type' => 'chart_xaxis',
          '#labels' => $month_labels,
        ],
      ],
      'rating_chart' => [
        '#type' => 'chart',
        '#chart_type' => 'column',
        '#title' => 'Satisfacción (1 a 5 estrellas)',
        '#chart_library' => 'chartjs',
        'series' => [
          '#type' => 'chart_data',
          '#title' => 'Calificaciones',
          '#data' => $rating_data,
        ],
        'xaxis' => [
          '#type' => 'chart_xaxis',
          '#labels' => $rating_labels,
        ],
      ],
      'help' => [
        '#markup' => '<p>Los tableros se alimentan de las solicitudes, quejas, reservaciones e incidentes. Mesa directiva tiene acceso de solo lectura.</p>',
      ],
    ];
  }

  /**
   * Audit log view.
   */
  public function audit(): array {
    $query = \Drupal::database()->select('watchdog', 'w')
      ->fields('w', ['wid', 'timestamp', 'message', 'variables', 'uid'])
      ->condition('type', 'auditoria')
      ->orderBy('timestamp', 'DESC')
      ->range(0, 100);
    $rows = [];
    foreach ($query->execute() as $row) {
      $vars = $row->variables ? unserialize($row->variables, ['allowed_classes' => FALSE]) : [];
      $rows[] = [
        \Drupal::service('date.formatter')->format($row->timestamp, 'short'),
        t($row->message, is_array($vars) ? $vars : []),
        $row->uid,
      ];
    }
    return [
      'table' => [
        '#type' => 'table',
        '#header' => ['Fecha', 'Evento', 'UID'],
        '#rows' => $rows,
        '#empty' => 'Aún no hay eventos de auditoría.',
      ],
    ];
  }

  protected function maintenanceUsers(): array {
    $uids = $this->entityTypeManager()->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('roles', 'mantenimiento')
      ->execute();
    $out = [];
    foreach (User::loadMultiple($uids) as $user) {
      $out[$user->id()] = $user->getDisplayName();
    }
    return $out;
  }

  protected function nextStates(NodeInterface $node): array {
    return match ($node->bundle()) {
      'solicitud' => [
        'en_revision' => 'En revisión',
        'asignada' => 'Asignada',
        'en_proceso' => 'En proceso',
        'resuelta' => 'Resuelta',
        'cerrada' => 'Cerrada',
        'rechazada' => 'Rechazada',
      ],
      'queja' => [
        'en_investigacion' => 'En investigación',
        'accion_tomada' => 'Acción tomada',
        'cerrada' => 'Cerrada',
      ],
      'reservacion' => [
        'aprobada' => 'Aprobar',
        'rechazada' => 'Rechazar',
        'realizada' => 'Realizada',
        'cancelada' => 'Cancelar',
      ],
      default => [],
    };
  }

  protected function countByState(string $type, array $states): int {
    $nids = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(FALSE)->condition('type', $type)->execute();
    $n = 0;
    foreach (Node::loadMultiple($nids) as $node) {
      if ($node->hasField('moderation_state') && in_array($node->get('moderation_state')->value, $states, TRUE)) {
        $n++;
      }
    }
    return $n;
  }

  protected function countBundle(string $type): int {
    return (int) $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(FALSE)->condition('type', $type)->count()->execute();
  }

  protected function avgRating(): string {
    $nids = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(FALSE)->condition('type', 'solicitud')->exists('field_calificacion')->execute();
    if (!$nids) {
      return 'Sin dato';
    }
    $sum = 0;
    $n = 0;
    foreach (Node::loadMultiple($nids) as $node) {
      $sum += (int) $node->get('field_calificacion')->value;
      $n++;
    }
    return $n ? number_format($sum / $n, 1) : 'Sin dato';
  }

  protected function countOpenIncidents(): int {
    return (int) $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'incidente')
      ->condition('created', strtotime('-30 days'), '>=')
      ->count()
      ->execute();
  }

  /**
   * Last six months of incident counts.
   */
  protected function incidentsByMonth(): array {
    $labels = [];
    $data = [];
    $start = new \DateTimeImmutable('first day of this month');
    for ($i = 5; $i >= 0; $i--) {
      $month = $start->modify("-$i months");
      $from = $month->getTimestamp();
      $to = $month->modify('+1 month')->getTimestamp();
      $labels[] = $this->monthLabel((int) $month->format('n'));
      $data[] = (int) $this->entityTypeManager()->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'incidente')
        ->condition('created', $from, '>=')
        ->condition('created', $to, '<')
        ->count()
        ->execute();
    }
    return [$labels, $data];
  }

  /**
   * Counts of 1–5 star ratings.
   */
  protected function ratingDistribution(): array {
    $counts = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
    $nids = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(FALSE)->condition('type', 'solicitud')->exists('field_calificacion')->execute();
    foreach (Node::loadMultiple($nids) as $node) {
      $value = (int) $node->get('field_calificacion')->value;
      if (isset($counts[$value])) {
        $counts[$value]++;
      }
    }
    return [['1', '2', '3', '4', '5'], array_values($counts)];
  }

  protected function monthLabel(int $month): string {
    $names = [1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic'];
    return $names[$month] ?? (string) $month;
  }

}
