<?php

namespace Drupal\provenza_demo\Commands;

use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\provenza_demo\DemoContentGenerator;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Regenerates sample data.
 */
class DemoCommands extends DrushCommands {

  public function __construct(protected ClassResolverInterface $classResolver) {
    parent::__construct();
  }

  #[CLI\Command(name: 'provenza-demo:generate', aliases: ['pza-demo'])]
  public function generate(): void {
    $this->classResolver->getInstanceFromDefinition(DemoContentGenerator::class)->generate();
    $this->logger()->success('Datos de ejemplo generados.');
  }

}
