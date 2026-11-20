<?php

namespace Drupal\provenza_core\EventSubscriber;

use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Envía a las visitas sin sesión al inicio de sesión en lugar de un 403.
 *
 * Los trámites y el panel del colono requieren cuenta. Al terminar de iniciar
 * sesión, Drupal regresa a la página que se intentaba abrir.
 */
class LoginRedirectSubscriber implements EventSubscriberInterface {

  public function __construct(protected AccountInterface $currentUser) {}

  public static function getSubscribedEvents(): array {
    // Antes que el manejador de excepciones de Drupal (prioridad 0).
    return [KernelEvents::EXCEPTION => ['onException', 50]];
  }

  public function onException(ExceptionEvent $event): void {
    if (!$event->getThrowable() instanceof AccessDeniedHttpException || $this->currentUser->isAuthenticated()) {
      return;
    }
    $request = $event->getRequest();
    if ($request->getRequestFormat() !== 'html' || str_starts_with($request->getPathInfo(), '/user')) {
      return;
    }
    $destino = $request->getRequestUri();
    $login = Url::fromRoute('user.login', [], ['query' => ['destination' => $destino]])->toString();
    $event->setResponse(new TrustedRedirectResponse($login, 302));
  }

}
