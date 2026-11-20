<?php

namespace Drupal\provenza_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\User;

/**
 * Email notification preferences for the current resident.
 */
class NotificationsForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'provenza_notifications';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $user = User::load($this->currentUser()->id());
    $form['intro'] = [
      '#markup' => '<p>Elige qué avisos quieres recibir por correo. El portal no envía mensajes por WhatsApp ni SMS.</p>',
    ];
    $map = [
      'field_notif_solicitudes' => 'Avisarme de mis solicitudes y quejas',
      'field_notif_avisos' => 'Avisarme de avisos comunitarios',
      'field_notif_reservaciones' => 'Avisarme de mis reservaciones',
    ];
    foreach ($map as $field => $label) {
      $form[$field] = [
        '#type' => 'checkbox',
        '#title' => $label,
        '#default_value' => $user && $user->hasField($field) ? (bool) $user->get($field)->value : TRUE,
      ];
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => 'Guardar preferencias',
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $user = User::load($this->currentUser()->id());
    if (!$user) {
      return;
    }
    foreach (['field_notif_solicitudes', 'field_notif_avisos', 'field_notif_reservaciones'] as $field) {
      if ($user->hasField($field)) {
        $user->set($field, $form_state->getValue($field) ? 1 : 0);
      }
    }
    $user->save();
    $this->messenger()->addStatus('Preferencias de correo guardadas.');
  }

}
