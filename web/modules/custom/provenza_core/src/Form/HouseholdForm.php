<?php

namespace Drupal\provenza_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * House record and household members for the current resident.
 */
class HouseholdForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'provenza_household';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $user = User::load($this->currentUser()->id());
    $house = $this->house($user);
    if (!$house) {
      $form['empty'] = [
        '#markup' => '<p>Tu cuenta aún no está ligada a un domicilio. Administración lo asigna al aprobar el registro.</p>',
      ];
      return $form;
    }

    $form['house'] = [
      '#markup' => '<p><strong>Domicilio:</strong> ' . $house->label() . '</p><p>' . ($house->get('field_calle')->value ?? '') . ' ' . ($house->get('field_numero')->value ?? '') . '. Estado: ' . ($house->get('field_estado_domicilio')->value ?? 'activo') . '.</p>',
    ];

    $members = $this->members($house->id());
    $rows = [];
    foreach ($members as $member) {
      $rows[] = [
        $member->get('field_nombre_completo')->value ?: $member->getDisplayName(),
        $member->getEmail(),
        $member->hasField('field_relacion') ? ($member->get('field_relacion')->value ?: 'Sin dato') : 'Sin dato',
        $member->isActive() ? 'Activa' : 'Inactiva',
      ];
    }
    $form['members'] = [
      '#type' => 'table',
      '#caption' => 'Personas del domicilio',
      '#header' => ['Nombre', 'Correo', 'Relación', 'Estado'],
      '#rows' => $rows,
      '#empty' => 'No hay familiares o inquilinos registrados.',
    ];

    if (!$this->currentUser()->hasPermission('manage household members')) {
      $form['hint'] = [
        '#markup' => '<p>Solo el residente titular puede agregar familiares o inquilinos.</p>',
      ];
      return $form;
    }

    $form['add'] = [
      '#type' => 'details',
      '#title' => 'Agregar familiar o inquilino',
      '#open' => TRUE,
    ];
    $form['add']['nombre'] = [
      '#type' => 'textfield',
      '#title' => 'Nombre completo',
      '#required' => TRUE,
    ];
    $form['add']['mail'] = [
      '#type' => 'email',
      '#title' => 'Correo',
      '#required' => TRUE,
    ];
    $form['add']['relacion'] = [
      '#type' => 'select',
      '#title' => 'Relación',
      '#options' => [
        'familiar' => 'Familiar',
        'inquilino' => 'Inquilino',
      ],
      '#required' => TRUE,
    ];
    $form['add']['telefono'] = [
      '#type' => 'tel',
      '#title' => 'Teléfono',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => 'Agregar al domicilio',
      '#button_type' => 'primary',
    ];
    $form['house_id'] = [
      '#type' => 'value',
      '#value' => $house->id(),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $mail = trim((string) $form_state->getValue('mail'));
    if ($mail && user_load_by_mail($mail)) {
      $form_state->setErrorByName('mail', 'Ese correo ya tiene una cuenta en el portal.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->currentUser()->hasPermission('manage household members')) {
      return;
    }
    $password = \Drupal::service('password_generator')->generate(12);
    $account = User::create([
      'name' => $form_state->getValue('mail'),
      'mail' => $form_state->getValue('mail'),
      'pass' => $password,
      'status' => 1,
    ]);
    $account->addRole('residente_familiar');
    if ($account->hasField('field_nombre_completo')) {
      $account->set('field_nombre_completo', $form_state->getValue('nombre'));
    }
    if ($account->hasField('field_telefono')) {
      $account->set('field_telefono', $form_state->getValue('telefono'));
    }
    if ($account->hasField('field_relacion')) {
      $account->set('field_relacion', $form_state->getValue('relacion'));
    }
    if ($account->hasField('field_domicilio')) {
      $account->set('field_domicilio', $form_state->getValue('house_id'));
    }
    $account->save();
    _user_mail_notify('register_no_approval_required', $account);
    $this->messenger()->addStatus('Se agregó a ' . $form_state->getValue('nombre') . '. Recibirá un correo para elegir su contraseña.');
  }

  protected function house(?UserInterface $user): ?Node {
    if (!$user || !$user->hasField('field_domicilio') || $user->get('field_domicilio')->isEmpty()) {
      return NULL;
    }
    $node = $user->get('field_domicilio')->entity;
    return $node instanceof Node ? $node : NULL;
  }

  /**
   * @return \Drupal\user\UserInterface[]
   */
  protected function members(int $house_id): array {
    $uids = \Drupal::entityTypeManager()->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', 0, '<>')
      ->condition('field_domicilio', $house_id)
      ->execute();
    return User::loadMultiple($uids);
  }

}
