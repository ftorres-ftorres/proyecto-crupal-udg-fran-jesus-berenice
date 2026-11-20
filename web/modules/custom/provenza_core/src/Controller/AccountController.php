<?php

namespace Drupal\provenza_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\provenza_core\Form\HouseholdForm;
use Drupal\provenza_core\Form\NotificationsForm;
use Drupal\user\Entity\User;

/**
 * Account pages: vehicles, house, notifications and security.
 */
class AccountController extends ControllerBase {

  /**
   * Lists vehicles on the resident profile.
   */
  public function vehicles(): array {
    $user = User::load($this->currentUser()->id());
    $rows = [];
    if ($user && $user->hasField('field_vehiculos')) {
      foreach ($user->get('field_vehiculos') as $item) {
        $p = $item->entity;
        if (!$p) {
          continue;
        }
        $rows[] = [
          $p->get('field_placa')->value,
          $p->get('field_marca')->value,
          $p->get('field_color')->value,
          $p->get('field_tag_acceso')->value,
        ];
      }
    }
    return [
      'intro' => [
        '#markup' => '<p>Vehículos y tags de acceso de tu domicilio. Para un tag nuevo o de reposición abre una <a href="/form/solicitud-servicio">solicitud de servicio</a> con categoría Tag de acceso.</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => ['Placa', 'Marca', 'Color', 'Tag'],
        '#rows' => $rows,
        '#empty' => 'No hay vehículos registrados. Edita tu perfil para agregar uno.',
      ],
      'profile' => [
        '#markup' => '<p><a class="button" href="/user/' . $this->currentUser()->id() . '/edit">Editar perfil</a></p>',
      ],
    ];
  }

  /**
   * House and household members.
   */
  public function household(): array {
    return $this->formBuilder()->getForm(HouseholdForm::class);
  }

  /**
   * Email notification preferences.
   */
  public function notifications(): array {
    return $this->formBuilder()->getForm(NotificationsForm::class);
  }

  /**
   * Password and second factor.
   */
  public function security(): array {
    $uid = $this->currentUser()->id();
    $needs_tfa = (bool) array_intersect($this->currentUser()->getRoles(), [
      'administracion',
      'webmaster',
    ]);
    $tfa = Url::fromUri('internal:/user/' . $uid . '/security/tfa')->toString();
    $edit = Url::fromUri('internal:/user/' . $uid . '/edit')->toString();
    $tfa_note = $needs_tfa
      ? '<p>Tu rol requiere un segundo factor (aplicación TOTP). Configúralo en <a href="' . $tfa . '">seguridad de dos factores</a>.</p>'
      : '<p>El segundo factor (TOTP) es obligatorio para las cuentas de Administración. En esta cuenta es opcional: puedes activarlo en <a href="' . $tfa . '">seguridad de dos factores</a>.</p>';
    return [
      '#markup' => '<div class="provenza-tutorial"><p>Cambia tu contraseña y revisa el segundo factor de acceso. Las notificaciones del portal se envían solo por correo.</p><p><a class="button button--primary" href="' . $edit . '">Cambiar contraseña</a></p>' . $tfa_note . '</div>',
    ];
  }

}
