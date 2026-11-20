<?php

namespace Drupal\provenza_core\EventSubscriber;

use Drupal\content_moderation\Event\ContentModerationStateChangedEvent;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\provenza_core\AuditLogger;
use Drupal\provenza_core\MailTemplateService;
use Drupal\user\UserInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Sends email and writes audit logs when tracking nodes change.
 */
class StatusChangeSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected MailTemplateService $mail,
    protected AuditLogger $audit,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = [];
    if (class_exists(ContentModerationStateChangedEvent::class)) {
      $events[ContentModerationStateChangedEvent::class] = ['onModerationChange'];
    }
    return $events;
  }

  /**
   * Reacts to a moderation transition.
   */
  public function onModerationChange($event): void {
    $entity = $event->getModeratedEntity();
    if (!$entity instanceof NodeInterface) {
      return;
    }
    $bundle = $entity->bundle();
    $map = [
      'solicitud' => 'solicitud_status',
      'queja' => 'queja_status',
      'reservacion' => 'reservacion_status',
    ];
    if (!isset($map[$bundle])) {
      return;
    }
    $this->audit->log('Cambio de estado @bundle @folio a @state', [
      '@bundle' => $bundle,
      '@folio' => $entity->hasField('field_folio') ? $entity->get('field_folio')->value : $entity->id(),
      '@state' => $event->getNewState(),
    ]);
    $owner = $entity->getOwner();
    if ($owner instanceof UserInterface && $this->wantsMail($owner, $bundle)) {
      $this->mail->send($map[$bundle], $owner, $entity);
    }
  }

  /**
   * Notification preference check.
   */
  protected function wantsMail(UserInterface $user, string $bundle): bool {
    $field = match ($bundle) {
      'solicitud', 'queja' => 'field_notif_solicitudes',
      'reservacion' => 'field_notif_reservaciones',
      default => NULL,
    };
    if (!$field || !$user->hasField($field)) {
      return TRUE;
    }
    $value = $user->get($field)->value;
    return $value === NULL || (bool) $value;
  }

}
