<?php

namespace Drupal\site_lockdown\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drush\Commands\DrushCommands;
use Drupal\node\NodeInterface;

class SiteLockdownCommands extends DrushCommands {

  public function __construct(
    protected StateInterface $state,
    protected EntityTypeManagerInterface $entityTypeManager
  ) {
    parent::__construct();
  }

  /**
   * Enable lockdown for a given storm-centre node.
   *
   * @command lockdown:enable
   * @param int $nid Node ID of storm-centre page to allow publicly.
   * @usage drush lockdown:enable 123
   */
  public function enable(int $nid): void {
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface) {
      $this->logger()->error("Node $nid not found.");
      return;
    }
    if ($node->bundle() !== 'storm-centre') {
      $this->logger()->error("Node $nid is not storm-centre.");
      return;
    }
    if (!$node->isPublished()) {
      $this->logger()->warning("Node $nid is unpublished. Public users will redirect to an unpublished page.");
    }

    $this->state->set('site_lockdown.enabled', TRUE);
    $this->state->set('site_lockdown.allowed_nid', $nid);
    $this->logger()->notice("Lockdown enabled. Allowed NID: $nid");
  }

  /**
   * Disable lockdown.
   *
   * @command lockdown:disable
   * @usage drush lockdown:disable
   */
  public function disable(): void {
    $this->state->set('site_lockdown.enabled', FALSE);
    $this->state->delete('site_lockdown.allowed_nid');
    $this->logger()->notice("Lockdown disabled.");
  }

}
