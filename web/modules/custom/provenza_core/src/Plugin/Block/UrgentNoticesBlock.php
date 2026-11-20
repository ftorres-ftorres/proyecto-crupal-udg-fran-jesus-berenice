<?php

namespace Drupal\provenza_core\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\Entity\Node;

/**
 * Urgent announcement banner.
 */
#[Block(
  id: 'provenza_urgent_notices',
  admin_label: new TranslatableMarkup('Avisos urgentes Provenza'),
)]
class UrgentNoticesBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $nids = \Drupal::entityQuery('node')->accessCheck(TRUE)
      ->condition('type', 'aviso')
      ->condition('status', 1)
      ->condition('field_urgente', 1)
      ->sort('created', 'DESC')
      ->range(0, 3)
      ->execute();
    if (!$nids) {
      return [];
    }
    $items = [];
    foreach (Node::loadMultiple($nids) as $node) {
      $items[] = [
        '#type' => 'link',
        '#title' => $node->label(),
        '#url' => $node->toUrl(),
      ];
    }
    return [
      '#theme' => 'item_list',
      '#title' => 'Avisos urgentes',
      '#items' => $items,
      '#attributes' => ['class' => ['provenza-urgent']],
      '#cache' => ['tags' => ['node_list:aviso']],
    ];
  }

}
