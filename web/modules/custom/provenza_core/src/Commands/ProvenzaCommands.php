<?php

namespace Drupal\provenza_core\Commands;

use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\provenza_core\Install\SiteInstaller;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Provenza.
 */
class ProvenzaCommands extends DrushCommands {

  public function __construct(protected ClassResolverInterface $classResolver) {
    parent::__construct();
  }

  /**
   * Re-runs structural setup (safe if already installed).
   */
  #[CLI\Command(name: 'provenza:setup', aliases: ['pza-setup'])]
  public function setup(): void {
    $this->classResolver->getInstanceFromDefinition(SiteInstaller::class)->install();
    $this->logger()->success('Estructura de Provenza actualizada.');
  }

}
