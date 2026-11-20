<?php

namespace Drupal\provenza_core\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\Entity\Node;
use Drupal\provenza_core\MailTemplateService;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Emails an announcement to residents who opted in.
 */
#[QueueWorker(
  id: 'provenza_announcement_mail',
  title: new TranslatableMarkup('Correos de avisos'),
  cron: ['time' => 30],
)]
class AnnouncementMail extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected MailTemplateService $mail) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('provenza_core.mail_templates'));
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $node = Node::load($data['nid'] ?? 0);
    if (!$node) {
      return;
    }
    $uids = \Drupal::entityQuery('user')->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('roles', ['residente_titular', 'residente_familiar'], 'IN')
      ->execute();
    foreach (User::loadMultiple($uids) as $user) {
      if ($user->hasField('field_notif_avisos') && $user->get('field_notif_avisos')->value === '0') {
        continue;
      }
      $this->mail->send('aviso', $user, $node);
    }
  }

}
