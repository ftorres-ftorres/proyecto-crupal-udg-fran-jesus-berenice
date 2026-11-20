<?php

namespace Drupal\provenza_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tablet-oriented guardhouse view.
 */
class GuardhouseController extends ControllerBase {

  /**
   * Today's expected visitors.
   */
  public function today(Request $request): array {
    $house = trim((string) $request->query->get('casa', ''));
    $plate = strtoupper(trim((string) $request->query->get('placa', '')));
    $today = date('Y-m-d');

    $query = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'visita')
      ->condition('field_estado_visita', ['programada', 'en_caseta', 'salio'], 'IN')
      ->sort('field_ventana_inicio', 'ASC');
    if ($plate) {
      $query->condition('field_placa', $plate, 'CONTAINS');
    }
    $nids = $query->execute();
    $visits = [];
    foreach ($this->entityTypeManager()->getStorage('node')->loadMultiple($nids) as $node) {
      /** @var \Drupal\node\NodeInterface $node */
      $start = substr((string) $node->get('field_ventana_inicio')->value, 0, 10);
      $end = substr((string) $node->get('field_ventana_fin')->value, 0, 10);
      if ($start && $start > $today) {
        continue;
      }
      if ($end && $end < $today && $node->get('field_estado_visita')->value === 'programada') {
        continue;
      }
      $house_label = '';
      if (!$node->get('field_domicilio')->isEmpty()) {
        $house_label = $node->get('field_domicilio')->entity?->label() ?? '';
      }
      if ($house && !str_contains(mb_strtolower($house_label), mb_strtolower($house))) {
        continue;
      }
      $token = \Drupal::csrfToken()->get('provenza_caseta_' . $node->id());
      $estado = (string) $node->get('field_estado_visita')->value;
      $estado_label = match ($estado) {
        'programada' => 'Programada',
        'en_caseta' => 'En caseta',
        'salio' => 'Salió',
        'vencida' => 'Vencida',
        default => $estado,
      };
      $visits[] = [
        'folio' => $node->get('field_folio')->value,
        'nombre' => $node->get('field_nombre_visita')->value,
        'casa' => $house_label,
        'placa' => $node->get('field_placa')->value,
        'ventana' => provenza_core_format_datetime((string) $node->get('field_ventana_inicio')->value) . ' – ' . provenza_core_format_datetime((string) $node->get('field_ventana_fin')->value),
        'estado' => $estado,
        'estado_label' => $estado_label,
        'entrada_url' => Url::fromRoute('provenza_core.caseta_check', ['node' => $node->id(), 'op' => 'entrada'], ['query' => ['token' => \Drupal::csrfToken()->get('provenza_core.caseta_check/' . $node->id() . '/entrada')]])->toString(),
        'salida_url' => Url::fromRoute('provenza_core.caseta_check', ['node' => $node->id(), 'op' => 'salida'], ['query' => ['token' => \Drupal::csrfToken()->get('provenza_core.caseta_check/' . $node->id() . '/salida')]])->toString(),
        'url' => $node->toUrl()->toString(),
      ];
    }

    return [
      '#theme' => 'provenza_caseta',
      '#visits' => $visits,
      '#filters' => [
        'casa' => $house,
        'placa' => $plate,
      ],
      '#attached' => ['library' => ['provenza_core/caseta']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Check-in or check-out.
   */
  public function check(NodeInterface $node, string $op): RedirectResponse {
    if ($node->bundle() !== 'visita') {
      throw $this->createNotFoundException();
    }
    $now = gmdate('Y-m-d\TH:i:s');
    if ($op === 'entrada') {
      $node->set('field_checkin', $now);
      $node->set('field_estado_visita', 'en_caseta');
      $this->messenger()->addStatus('Entrada registrada: ' . $node->get('field_nombre_visita')->value);
    }
    else {
      $node->set('field_checkout', $now);
      $node->set('field_estado_visita', 'salio');
      $this->messenger()->addStatus('Salida registrada: ' . $node->get('field_nombre_visita')->value);
    }
    $node->save();
    \Drupal::service('provenza_core.audit')->log('Caseta @op visita @folio', [
      '@op' => $op,
      '@folio' => $node->get('field_folio')->value,
    ]);
    return new RedirectResponse('/caseta');
  }

  /**
   * Shareable QR pass.
   */
  public function pass(NodeInterface $node): array {
    if ($node->bundle() !== 'visita') {
      throw $this->createNotFoundException();
    }
    $qr = '';
    if ($node->hasField('field_qr') && !$node->get('field_qr')->isEmpty()) {
      $file = $node->get('field_qr')->entity;
      if ($file) {
        $qr = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
      }
    }
    $house = $node->get('field_domicilio')->entity;
    $ventana = provenza_core_format_datetime((string) $node->get('field_ventana_inicio')->value) . ' a ' . provenza_core_format_datetime((string) $node->get('field_ventana_fin')->value);
    return [
      '#markup' => '<div class="provenza-pass"><h2>Pase de acceso</h2><p class="provenza-folio">' . $node->get('field_folio')->value . '</p><p><strong>' . $node->get('field_nombre_visita')->value . '</strong></p><p>Casa: ' . ($house ? $house->label() : '—') . '</p><p>Ventana: ' . $ventana . '</p>' . ($qr ? '<p><img alt="Código QR del pase" src="' . $qr . '" width="280" height="280" /></p>' : '') . '<p>Muestra este pase en caseta.</p></div>',
      '#attached' => ['library' => ['provenza_core/portal']],
    ];
  }

}
