<?php

namespace Drupal\provenza_core\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;

/**
 * Assigns selected service requests to a maintenance staff member.
 */
#[Action(
  id: 'provenza_assign_maintenance',
  label: new TranslatableMarkup('Asignar a mantenimiento'),
  type: 'node'
)]
class AssignToMaintenance extends ViewsBulkOperationsActionBase implements PluginFormInterface {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    if (!$entity instanceof NodeInterface || $entity->bundle() !== 'solicitud') {
      return $this->t('Solo se asignan solicitudes de servicio.');
    }
    $uid = (int) ($this->configuration['assign_to'] ?? 0);
    $user = $uid ? User::load($uid) : NULL;
    if (!$user) {
      return $this->t('Elige a quién asignar.');
    }
    $entity->set('field_asignado', $user->id());
    if ($entity->hasField('moderation_state')) {
      $entity->set('moderation_state', 'asignada');
    }
    $entity->save();
    \Drupal::service('provenza_core.audit')->log('Asignó @folio a @uid', [
      '@folio' => $entity->get('field_folio')->value,
      '@uid' => $user->id(),
    ]);
    return $this->t('Asignada a @name.', ['@name' => $user->getDisplayName()]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['assign_to'] = [
      '#type' => 'select',
      '#title' => $this->t('Asignar a'),
      '#options' => $this->maintenanceUsers(),
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $account = $account ?: \Drupal::currentUser();
    $allowed = $object instanceof NodeInterface
      && $object->bundle() === 'solicitud'
      && $account->hasPermission('assign service requests');
    $result = AccessResult::allowedIf($allowed);
    return $return_as_object ? $result : $result->isAllowed();
  }

  /**
   * Active maintenance accounts.
   */
  protected function maintenanceUsers(): array {
    $uids = \Drupal::entityQuery('user')->accessCheck(FALSE)
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
