<?php

namespace Drupal\provenza_core\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
 * Simple search box.
 */
#[Block(
  id: 'provenza_search_form',
  admin_label: new TranslatableMarkup('Buscar Provenza'),
)]
class SearchFormBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $action = Url::fromRoute('provenza_core.search')->toString();
    return [
      '#markup' => '<form class="provenza-search-form" method="get" action="' . $action . '"><label for="provenza-q" class="visually-hidden">Buscar</label><input id="provenza-q" name="q" type="search" placeholder="Buscar en el portal" /><button type="submit">Buscar</button></form>',
      '#cache' => ['contexts' => ['url.path']],
    ];
  }

}
