<?php

namespace Drupal\site_lockdown\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Url;

class SiteLockdownRequestSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected StateInterface $state,
    protected ConfigFactoryInterface $configFactory,
    protected AccountInterface $currentUser,
    protected CurrentPathStack $currentPath,
    protected RequestStack $requestStack,
  ) {}

  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => ['onRequest', 30]];
  }

  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    if (!(bool) $this->state->get('site_lockdown.enabled', FALSE)) {
      return; // ✅ when unpublished -> lockdown off -> no redirects
    }

    // ✅ bypass for selected roles via permission
    if ($this->currentUser->hasPermission('bypass site lockdown')) {
      return;
    }

    $request = $event->getRequest();
    $route_name = $request->attributes->get('_route') ?? '';
    $path = $this->currentPath->getPath();

    // ✅ allow login routes always
    $allowed_routes = ['user.login', 'user.logout', 'user.pass'];
    if (in_array($route_name, $allowed_routes, TRUE)) {
      return;
    }

    // ✅ allow assets/files (avoid broken CSS/JS)
    if (str_starts_with($path, '/core/') || str_starts_with($path, '/themes/') || str_starts_with($path, '/sites/')) {
      return;
    }

    $allowed_nid = (int) $this->state->get('site_lockdown.allowed_nid', 0);
    if (!$allowed_nid) {
      return;
    }

    // ✅ allow ONLY the allowed storm-centre node canonical page
    if ($route_name === 'entity.node.canonical') {
      $node = $request->attributes->get('node');
      if ($node && (int) $node->id() === $allowed_nid) {
        return;
      }
    }

    // ✅ homepage redirect can be disabled via config
    $config = $this->configFactory->get('site_lockdown.settings');
    $allow_home = (bool) ($config->get('allow_homepage_redirect') ?? TRUE);
    if (!$allow_home && ($path === '/' || $route_name === '<front>')) {
      return;
    }

    // ✅ redirect ALL other anonymous requests to storm-centre page (302)
    $url = Url::fromRoute('entity.node.canonical', ['node' => $allowed_nid])->toString();
    $event->setResponse(new RedirectResponse($url, 302));
  }
}
