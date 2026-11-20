<?php

namespace Drupal\provenza_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\user\UserInterface;

/**
 * Approves a resident registration and links the house.
 */
class ApproveUserForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'provenza_approve_user';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?UserInterface $user = NULL): array {
    if (!$user) {
      return $form;
    }
    $form_state->set('approve_uid', $user->id());
    $house_number = $user->hasField('field_numero_casa') ? trim((string) $user->get('field_numero_casa')->value) : '';
    $matches = $this->matchingHouses($house_number);
    $default = count($matches) === 1 ? (string) array_key_first($matches) : '';

    $form['intro'] = [
      '#markup' => '<p><strong>' . $user->getDisplayName() . '</strong> · ' . $user->getEmail() . ($house_number ? ' · Casa declarada: ' . $house_number : '') . '</p>',
    ];
    $form['casa'] = [
      '#type' => 'select',
      '#title' => 'Domicilio',
      '#options' => ['' => '- Seleccionar domicilio -'] + $this->houseOptions(),
      '#default_value' => $default,
      '#required' => TRUE,
      '#description' => count($matches) === 1
        ? 'Se encontró un domicilio activo con ese número de casa.'
        : (count($matches) > 1
          ? 'Hay más de un domicilio con ese número. Elige el correcto.'
          : 'No hay un domicilio activo con ese número. Elige el domicilio o créalo antes de aprobar.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => 'Aprobar y vincular domicilio',
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => 'Cancelar',
      '#url' => Url::fromRoute('provenza_core.residents'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $user = \Drupal\user\Entity\User::load((int) $form_state->get('approve_uid'));
    $house = Node::load((int) $form_state->getValue('casa'));
    if (!$user || !$house || $house->bundle() !== 'domicilio') {
      $this->messenger()->addError('No se pudo vincular el domicilio. Elige una casa activa.');
      return;
    }

    $user->activate();
    if (!$user->hasRole('residente_titular') && !$user->hasRole('residente_familiar')) {
      $user->addRole('residente_titular');
    }
    if ($user->hasField('field_domicilio')) {
      $user->set('field_domicilio', $house->id());
    }
    if ($user->hasField('field_relacion')) {
      $user->set('field_relacion', 'titular');
    }
    $user->save();

    if ($house->hasField('field_titular')) {
      $house->set('field_titular', $user->id());
      $house->save();
    }

    \Drupal::service('provenza_core.audit')->log('Registro aprobado uid @uid en domicilio @nid', [
      '@uid' => $user->id(),
      '@nid' => $house->id(),
    ]);
    $this->messenger()->addStatus('Registro aprobado y vinculado a ' . $house->label() . '.');
    $form_state->setRedirect('provenza_core.residents');
  }

  /**
   * Active houses keyed by nid.
   */
  protected function houseOptions(): array {
    $nids = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'domicilio')
      ->condition('field_estado_domicilio', 'activo')
      ->sort('title')
      ->execute();
    $options = [];
    foreach (Node::loadMultiple($nids) as $node) {
      $options[$node->id()] = $node->label();
    }
    return $options;
  }

  /**
   * Active houses that match the number given at registration.
   *
   * @return \Drupal\node\NodeInterface[]
   */
  protected function matchingHouses(string $raw): array {
    if ($raw === '') {
      return [];
    }
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'domicilio')
      ->condition('field_estado_domicilio', 'activo');
    $or = $query->orConditionGroup()
      ->condition('field_numero', $raw)
      ->condition('title', $raw)
      ->condition('title', $raw, 'CONTAINS');
    $query->condition($or);
    return $storage->loadMultiple($query->execute());
  }

}
