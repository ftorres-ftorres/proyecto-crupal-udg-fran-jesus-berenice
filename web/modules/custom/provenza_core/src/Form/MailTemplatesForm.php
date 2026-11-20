<?php

namespace Drupal\provenza_core\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Editable Spanish email templates.
 */
class MailTemplatesForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'provenza_mail_templates';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['provenza_core.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $templates = $this->config('provenza_core.settings')->get('mail_templates') ?? [];
    $labels = [
      'solicitud_status' => 'Cambio de estado de solicitud',
      'queja_status' => 'Cambio de estado de queja',
      'reservacion_status' => 'Cambio de estado de reservación',
      'asignacion' => 'Asignación a mantenimiento',
      'aviso' => 'Aviso comunitario',
    ];
    $form['help'] = [
      '#markup' => '<p>Puede usar tokens como <code>[user:display-name]</code>, <code>[node:folio]</code>, <code>[node:field_folio:value]</code>, <code>[node:url]</code> y <code>[node:moderation_state]</code>.</p>',
    ];
    foreach ($labels as $id => $label) {
      $form[$id] = [
        '#type' => 'details',
        '#title' => $label,
        '#open' => FALSE,
      ];
      $form[$id][$id . '_subject'] = [
        '#type' => 'textfield',
        '#title' => 'Asunto',
        '#default_value' => $templates[$id]['subject'] ?? '',
      ];
      $form[$id][$id . '_body'] = [
        '#type' => 'textarea',
        '#title' => 'Cuerpo',
        '#rows' => 8,
        '#default_value' => $templates[$id]['body'] ?? '',
      ];
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $templates = [];
    foreach (['solicitud_status', 'queja_status', 'reservacion_status', 'asignacion', 'aviso'] as $id) {
      $templates[$id] = [
        'subject' => $form_state->getValue($id . '_subject'),
        'body' => $form_state->getValue($id . '_body'),
      ];
    }
    $this->config('provenza_core.settings')->set('mail_templates', $templates)->save();
    parent::submitForm($form, $form_state);
  }

}
