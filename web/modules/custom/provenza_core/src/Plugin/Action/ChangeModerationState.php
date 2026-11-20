<?php

namespace Drupal\provenza_core\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;

/**
 * Changes the workflow status of selected tracking nodes.
 */
#[Action(
  id: 'provenza_change_state',
  label: new TranslatableMarkup('Cambiar estado'),
  type: 'node'
)]
class ChangeModerationState extends ViewsBulkOperationsActionBase implements PluginFormInterface {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    if (!$entity instanceof NodeInterface || !$entity->hasField('moderation_state')) {
      return $this->t('Sin flujo de trabajo.');
    }
    $state = (string) ($this->configuration['new_state'] ?? '');
    if ($state === '') {
      return $this->t('Elige un estado.');
    }
    $entity->set('moderation_state', $state);
    $entity->save();
    \Drupal::service('provenza_core.audit')->log('Estado de @folio → @state', [
      '@folio' => $entity->hasField('field_folio') ? $entity->get('field_folio')->value : $entity->id(),
      '@state' => $state,
    ]);
    return $this->t('Estado actualizado.');
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['new_state'] = [
      '#type' => 'select',
      '#title' => $this->t('Nuevo estado'),
      '#required' => TRUE,
      '#options' => [
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
      ],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $account = $account ?: \Drupal::currentUser();
    $allowed = $object instanceof NodeInterface && $account->hasPermission('access provenza admin');
    $result = AccessResult::allowedIf($allowed);
    return $return_as_object ? $result : $result->isAllowed();
  }

}
