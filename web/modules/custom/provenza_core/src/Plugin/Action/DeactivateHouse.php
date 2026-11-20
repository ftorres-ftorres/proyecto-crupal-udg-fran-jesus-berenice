<?php

namespace Drupal\provenza_core\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;

/**
 * Deactivates a house when ownership changes, keeping history.
 */
#[Action(
  id: 'provenza_deactivate_house',
  label: new TranslatableMarkup('Desactivar domicilio (cambio de titular)'),
  type: 'node',
)]
class DeactivateHouse extends ActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL): void {
    if (!$entity instanceof NodeInterface || $entity->bundle() !== 'domicilio') {
      return;
    }
    $entity->set('field_estado_domicilio', 'desactivado');
    $entity->save();
    $uids = \Drupal::entityQuery('user')->accessCheck(FALSE)
      ->condition('field_domicilio', $entity->id())
      ->execute();
    foreach (User::loadMultiple($uids) as $user) {
      $user->block();
      $user->save();
    }
    \Drupal::service('provenza_core.audit')->log('Domicilio @nid desactivado por cambio de titular', ['@nid' => $entity->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $account = $account ?: \Drupal::currentUser();
    $result = AccessResult::allowedIf($account->hasPermission('manage provenza residents'));
    return $return_as_object ? $result : $result->isAllowed();
  }

}
