<?php

namespace Drupal\provenza_core;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Utility\Token;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

/**
 * Sends transactional email from editable Spanish templates.
 */
class MailTemplateService {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected MailManagerInterface $mailManager,
    protected Token $token,
    protected LoggerChannelInterface $logger,
    protected LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Sends a named template to a user about a node.
   */
  public function send(string $template_id, UserInterface $account, ?NodeInterface $node = NULL): bool {
    if ($account->isAnonymous() || !$account->isActive() || !$account->getEmail()) {
      return FALSE;
    }
    $templates = $this->configFactory->get('provenza_core.settings')->get('mail_templates') ?? [];
    if (empty($templates[$template_id]['subject']) || empty($templates[$template_id]['body'])) {
      return FALSE;
    }

    $data = ['user' => $account];
    if ($node) {
      $data['node'] = $node;
    }
    $options = ['clear' => TRUE, 'langcode' => 'es'];
    $subject = $this->token->replace($templates[$template_id]['subject'], $data, $options);
    $body = $this->token->replace($templates[$template_id]['body'], $data, $options);

    $langcode = $account->getPreferredLangcode() ?: $this->languageManager->getDefaultLanguage()->getId();
    $result = $this->mailManager->mail('provenza_core', $template_id, $account->getEmail(), $langcode, [
      'subject' => $subject,
      'body' => $body,
      'node' => $node,
    ], NULL, TRUE);

    return !empty($result['result']);
  }

}
