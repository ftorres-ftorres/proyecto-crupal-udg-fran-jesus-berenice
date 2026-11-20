<?php

namespace Drupal\provenza_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;

/**
 * Unified staff queue with filters, assignment, bulk actions and export.
 */
class QueueBulkForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'provenza_queue_bulk';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = $this->getRequest();
    $type = (string) $request->query->get('tipo', 'all');
    $state = (string) $request->query->get('estado', '');

    $form['#method'] = 'post';
    $form['filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['provenza-filters']],
    ];
    $form['filters']['tipo'] = [
      '#type' => 'select',
      '#title' => 'Tipo',
      '#options' => [
        'all' => 'Todos',
        'solicitud' => 'Solicitudes',
        'queja' => 'Quejas',
        'reservacion' => 'Reservaciones',
        'incidente' => 'Incidentes',
      ],
      '#default_value' => $type,
    ];
    $form['filters']['estado'] = [
      '#type' => 'textfield',
      '#title' => 'Estado',
      '#size' => 20,
      '#default_value' => $state,
    ];
    $form['filters']['filter'] = [
      '#type' => 'submit',
      '#value' => 'Filtrar',
      '#submit' => ['::filterSubmit'],
      '#limit_validation_errors' => [['tipo'], ['estado']],
    ];
    $form['filters']['export'] = [
      '#markup' => ' <a href="/admin/provenza/bandeja/exportar">Exportar CSV</a>',
    ];

    $options = [];
    foreach ($this->loadItems($type, $state) as $node) {
      $folio = $node->hasField('field_folio') ? $node->get('field_folio')->value : $node->id();
      $options[$node->id()] = [
        'folio' => $folio,
        'tipo' => $node->type->entity->label(),
        'titulo' => Link::fromTextAndUrl($node->label(), $node->toUrl()),
        'estado' => $node->hasField('moderation_state') ? ($node->get('moderation_state')->value ?: 'Sin dato') : 'Sin dato',
        'fecha' => \Drupal::service('date.formatter')->format($node->getCreatedTime(), 'short'),
      ];
    }

    $form['items'] = [
      '#type' => 'tableselect',
      '#header' => [
        'folio' => 'Folio',
        'tipo' => 'Tipo',
        'titulo' => 'Título',
        'estado' => 'Estado',
        'fecha' => 'Fecha',
      ],
      '#options' => $options,
      '#empty' => 'No hay elementos.',
      '#attributes' => ['class' => ['provenza-table']],
    ];

    $form['bulk'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['provenza-filters']],
    ];
    $form['bulk']['assign_to'] = [
      '#type' => 'select',
      '#title' => 'Asignar a',
      '#options' => ['' => '- Mantenimiento -'] + $this->maintenanceUsers(),
    ];
    $form['bulk']['new_state'] = [
      '#type' => 'select',
      '#title' => 'Cambiar estado',
      '#options' => [
        '' => '- Estado -',
        'en_revision' => 'En revisión',
        'asignada' => 'Asignada',
        'en_proceso' => 'En proceso',
        'resuelta' => 'Resuelta',
        'cerrada' => 'Cerrada',
        'rechazada' => 'Rechazada',
        'en_investigacion' => 'En investigación',
        'accion_tomada' => 'Acción tomada',
        'aprobada' => 'Aprobada',
        'realizada' => 'Realizada',
        'cancelada' => 'Cancelada',
      ],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['assign'] = [
      '#type' => 'submit',
      '#value' => 'Asignar selección',
      '#submit' => ['::assignSubmit'],
    ];
    $form['actions']['status'] = [
      '#type' => 'submit',
      '#value' => 'Cambiar estado de la selección',
      '#submit' => ['::statusSubmit'],
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * Applies GET filters.
   */
  public function filterSubmit(array &$form, FormStateInterface $form_state): void {
    $form_state->setRedirect('provenza_core.queue', [], [
      'query' => [
        'tipo' => $form_state->getValue('tipo'),
        'estado' => $form_state->getValue('estado'),
      ],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Default submit unused; named buttons handle actions.
  }

  /**
   * Assigns selected requests to a maintenance user.
   */
  public function assignSubmit(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $form_state->getValue('assign_to');
    $user = $uid ? User::load($uid) : NULL;
    if (!$user) {
      $this->messenger()->addError('Elige a quién asignar.');
      return;
    }
    $count = 0;
    foreach ($this->selectedNodes($form_state) as $node) {
      if ($node->bundle() !== 'solicitud' || !$node->hasField('field_asignado')) {
        continue;
      }
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
      $count++;
    }
    $this->messenger()->addStatus($count ? "Se asignaron $count solicitudes." : 'No había solicitudes en la selección.');
  }

  /**
   * Changes moderation state of the selection.
   */
  public function statusSubmit(array &$form, FormStateInterface $form_state): void {
    $state = (string) $form_state->getValue('new_state');
    if ($state === '') {
      $this->messenger()->addError('Elige un estado.');
      return;
    }
    $count = 0;
    foreach ($this->selectedNodes($form_state) as $node) {
      if (!$node->hasField('moderation_state')) {
        continue;
      }
      $node->set('moderation_state', $state);
      $node->save();
      \Drupal::service('provenza_core.audit')->log('Estado de @folio → @state', [
        '@folio' => $node->hasField('field_folio') ? $node->get('field_folio')->value : $node->id(),
        '@state' => $state,
      ]);
      $count++;
    }
    $this->messenger()->addStatus($count ? "Se actualizó el estado de $count expedientes." : 'Ningún expediente de la selección tiene flujo de trabajo.');
  }

  /**
   * @return \Drupal\node\NodeInterface[]
   */
  protected function selectedNodes(FormStateInterface $form_state): array {
    $ids = array_filter($form_state->getValue('items') ?? []);
    return $ids ? Node::loadMultiple($ids) : [];
  }

  /**
   * @return \Drupal\node\NodeInterface[]
   */
  protected function loadItems(string $type, string $state): array {
    $types = ['solicitud', 'queja', 'reservacion', 'incidente'];
    $query = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
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
    if ($state !== '') {
      $nodes = array_filter($nodes, fn (NodeInterface $n) => $n->hasField('moderation_state') && $n->get('moderation_state')->value === $state);
    }
    return $nodes;
  }

  protected function maintenanceUsers(): array {
    $uids = \Drupal::entityTypeManager()->getStorage('user')->getQuery()
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

}
