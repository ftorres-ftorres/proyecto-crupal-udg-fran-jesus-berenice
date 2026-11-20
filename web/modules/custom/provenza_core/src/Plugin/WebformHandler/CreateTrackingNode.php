<?php

namespace Drupal\provenza_core\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Creates or updates a tracking node from a webform submission.
 *
 * @WebformHandler(
 *   id = "provenza_create_node",
 *   label = @Translation("Crear nodo de seguimiento"),
 *   category = @Translation("Provenza"),
 *   description = @Translation("Crea la solicitud, queja, reservación, visita o incidente a partir del formulario."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 * )
 */
class CreateTrackingNode extends WebformHandlerBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'node_type' => '',
      'action' => 'create',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['node_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tipo de contenido'),
      '#default_value' => $this->configuration['node_type'],
    ];
    $form['action'] = [
      '#type' => 'select',
      '#title' => $this->t('Acción'),
      '#options' => ['create' => 'Crear', 'rate' => 'Calificar'],
      '#default_value' => $this->configuration['action'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['node_type'] = $form_state->getValue('node_type');
    $this->configuration['action'] = $form_state->getValue('action');
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE): void {
    if ($update) {
      return;
    }
    $data = $webform_submission->getData();
    $account = \Drupal::currentUser();
    $house = 0;
    if ($account->isAuthenticated()) {
      $user = \Drupal::entityTypeManager()->getStorage('user')->load($account->id());
      if ($user && $user->hasField('field_domicilio') && !$user->get('field_domicilio')->isEmpty()) {
        $house = (int) $user->get('field_domicilio')->target_id;
      }
    }

    if (($this->configuration['action'] ?? 'create') === 'rate') {
      $this->rate($data, $account->id());
      return;
    }

    $type = $this->configuration['node_type'];
    if (!$type) {
      return;
    }

    $node = Node::create([
      'type' => $type,
      'uid' => $account->id() ?: 0,
      'status' => 1,
      'title' => $this->titleFor($type, $data),
    ]);
    if ($house && $node->hasField('field_domicilio')) {
      $node->set('field_domicilio', $house);
    }

    switch ($type) {
      case 'solicitud':
        $node->set('field_categoria_sol', $data['categoria'] ?? NULL);
        $node->set('field_ubicacion', $data['ubicacion'] ?? '');
        $node->set('field_descripcion', ['value' => $data['descripcion'] ?? '', 'format' => 'basic_html']);
        if (!empty($data['fecha'])) {
          $node->set('field_fecha_recoleccion', $data['fecha']);
          $residuos = $this->termByName('categorias_solicitud', 'Residuos');
          if ($residuos) {
            $node->set('field_categoria_sol', $residuos);
          }
        }
        $node->set('moderation_state', 'recibida');
        break;

      case 'queja':
        $node->set('field_tipo_queja', $data['tipo'] ?? NULL);
        $node->set('field_confidencial', !empty($data['confidencial']));
        $node->set('field_descripcion', ['value' => $data['descripcion'] ?? '', 'format' => 'basic_html']);
        $node->set('moderation_state', 'recibida');
        break;

      case 'reservacion':
        $salon = $this->nodeByCode('salon');
        if ($salon) {
          $node->set('field_instalacion', $salon);
        }
        $node->set('field_fecha_inicio', $this->toDatetime($data['fecha_inicio'] ?? ''));
        $node->set('field_fecha_fin', $this->toDatetime($data['fecha_fin'] ?? ''));
        $node->set('field_invitados', $data['invitados'] ?? 0);
        $node->set('field_tipo_evento_txt', $data['tipo_evento'] ?? '');
        $node->set('field_acepta_reglas', !empty($data['acepta_reglas']));
        $node->set('moderation_state', 'solicitada');
        break;

      case 'visita':
        $node->set('field_nombre_visita', $data['nombre'] ?? '');
        $node->set('field_tipo_autorizacion', $data['tipo'] ?? 'visita');
        $node->set('field_ventana_inicio', $this->toDatetime($data['inicio'] ?? ''));
        $node->set('field_ventana_fin', $this->toDatetime($data['fin'] ?? ''));
        $node->set('field_placa', strtoupper($data['placa'] ?? ''));
        $node->set('field_num_personas', $data['personas'] ?? 1);
        $node->set('field_recurrente', !empty($data['recurrente']) || ($data['tipo'] ?? '') === 'recurrente');
        if (!empty($data['caducidad'])) {
          $node->set('field_caducidad', $data['caducidad']);
        }
        $node->set('field_estado_visita', 'programada');
        break;

      case 'incidente':
        $node->set('field_fecha_incidente', $this->toDatetime($data['fecha'] ?? ''));
        $node->set('field_ubicacion', $data['lugar'] ?? '');
        $node->set('field_descripcion', ['value' => $data['descripcion'] ?? '', 'format' => 'basic_html']);
        break;
    }

    $this->attachPhotos($node, $data['fotos'] ?? []);
    $node->save();
    $this->messenger()->addStatus($this->t('Se registró el folio @folio.', [
      '@folio' => $node->hasField('field_folio') ? $node->get('field_folio')->value : $node->id(),
    ]));
  }

  /**
   * Applies a rating to a resolved request.
   */
  protected function rate(array $data, int $uid): void {
    $folio = trim($data['folio'] ?? '');
    if (!$folio) {
      $this->messenger()->addError('Indica el folio.');
      return;
    }
    $nids = \Drupal::entityQuery('node')->accessCheck(TRUE)
      ->condition('type', 'solicitud')
      ->condition('field_folio', $folio)
      ->condition('uid', $uid)
      ->execute();
    if (!$nids) {
      $this->messenger()->addError('No se encontró una solicitud tuya con ese folio.');
      return;
    }
    $node = Node::load(reset($nids));
    if ($node->get('moderation_state')->value !== 'resuelta') {
      $this->messenger()->addError('Solo puedes calificar solicitudes en estado Resuelta.');
      return;
    }
    if (!$node->get('field_calificacion')->isEmpty()) {
      $this->messenger()->addError('Esta solicitud ya fue calificada.');
      return;
    }
    $node->set('field_calificacion', (int) ($data['estrellas'] ?? 0));
    $node->set('field_comentario_calificacion', $data['comentario'] ?? '');
    $node->save();
    $this->messenger()->addStatus('Gracias por calificar el servicio.');
  }

  protected function titleFor(string $type, array $data): string {
    return match ($type) {
      'solicitud' => 'Solicitud: ' . ($data['ubicacion'] ?? 'servicio'),
      'queja' => 'Queja o sugerencia',
      'reservacion' => 'Reservación del salón de fiestas',
      'visita' => 'Visita: ' . ($data['nombre'] ?? ''),
      'incidente' => 'Incidente: ' . ($data['lugar'] ?? ''),
      default => 'Registro',
    };
  }

  protected function attachPhotos(Node $node, mixed $fotos): void {
    if (!$node->hasField('field_fotos') || empty($fotos)) {
      return;
    }
    $fids = is_array($fotos) ? $fotos : [$fotos];
    $items = [];
    foreach ($fids as $fid) {
      if ($fid && ($file = File::load($fid))) {
        $file->setPermanent();
        $file->save();
        $items[] = ['target_id' => $file->id(), 'alt' => 'Evidencia'];
      }
    }
    if ($items) {
      $node->set('field_fotos', $items);
    }
  }

  protected function toDatetime(mixed $value): ?string {
    if (is_array($value)) {
      $value = ($value['date'] ?? '') . 'T' . ($value['time'] ?? '00:00:00');
    }
    $value = (string) $value;
    if ($value === '') {
      return NULL;
    }
    $ts = strtotime($value);
    return $ts ? gmdate('Y-m-d\TH:i:s', $ts) : NULL;
  }

  protected function termByName(string $vid, string $name): ?int {
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties([
      'vid' => $vid,
      'name' => $name,
    ]);
    $term = reset($terms);
    return $term ? (int) $term->id() : NULL;
  }

  protected function nodeByCode(string $code): ?int {
    $nids = \Drupal::entityQuery('node')->accessCheck(FALSE)
      ->condition('type', 'instalacion')
      ->condition('field_codigo_instalacion', $code)
      ->range(0, 1)
      ->execute();
    return $nids ? (int) reset($nids) : NULL;
  }

}
