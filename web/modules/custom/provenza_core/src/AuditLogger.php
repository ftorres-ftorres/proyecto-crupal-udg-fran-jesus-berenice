<?php

namespace Drupal\provenza_core;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Writes audit events to the auditoria watchdog channel.
 */
class AuditLogger {

  public function __construct(
    protected LoggerChannelInterface $logger,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * Logs an audit event.
   */
  public function log(string $message, array $context = []): void {
    $context += [
      '@uid' => $this->currentUser->id(),
      '@name' => $this->currentUser->getDisplayName(),
    ];
    $this->logger->notice($message . ' (usuario @name / uid @uid)', $context);
  }

}
