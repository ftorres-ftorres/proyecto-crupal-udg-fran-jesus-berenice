<?php

namespace Drupal\provenza_core\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
 * Home quick-action tiles for residents.
 */
#[Block(
  id: 'provenza_quick_actions',
  admin_label: new TranslatableMarkup('Acciones rápidas Provenza'),
)]
class QuickActionsBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $items = [
      ['title' => 'Nueva solicitud', 'url' => '/form/solicitud-servicio'],
      ['title' => 'Reservar salón', 'url' => '/form/reservar-salon'],
      ['title' => 'Registrar visita', 'url' => '/form/registrar-visita'],
      ['title' => 'Reportar incidente', 'url' => '/form/reportar-incidente'],
      ['title' => 'Ver tarifas', 'url' => '/comunidad/cuotas-y-tarifas'],
    ];
    return [
      '#theme' => 'provenza_tiles',
      '#items' => $items,
      '#cache' => ['contexts' => ['user.roles']],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    return AccessResult::allowedIf((bool) array_intersect($account->getRoles(), [
      'residente_titular',
      'residente_familiar',
      'administracion',
      'webmaster',
    ]));
  }

}
