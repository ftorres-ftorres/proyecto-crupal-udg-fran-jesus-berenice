<?php

namespace Drupal\provenza_core\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\provenza_core\Controller\PortalController;

/**
 * Ballroom availability calendar.
 */
#[Block(
  id: 'provenza_calendar',
  admin_label: new TranslatableMarkup('Calendario del salón de fiestas'),
)]
class AvailabilityCalendarBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return \Drupal::classResolver(PortalController::class)->calendarBuild();
  }

}
