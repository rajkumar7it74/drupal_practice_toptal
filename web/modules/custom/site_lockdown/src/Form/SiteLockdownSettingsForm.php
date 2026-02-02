<?php

namespace Drupal\site_lockdown\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;

class SiteLockdownSettingsForm extends ConfigFormBase {

  public function getFormId(): string {
    return 'site_lockdown_settings_form';
  }

  protected function getEditableConfigNames(): array {
    return ['site_lockdown.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('site_lockdown.settings');
    $state = \Drupal::state();

    $enabled = (bool) $state->get('site_lockdown.enabled', FALSE);
    $nid = (int) $state->get('site_lockdown.allowed_nid', 0);

    $form['allow_homepage_redirect'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow homepage redirect to Storm Centre page'),
      '#default_value' => (bool) ($config->get('allow_homepage_redirect') ?? TRUE),
      '#description' => $this->t('If enabled, the homepage (/) will redirect to the active Storm Centre page during lockdown.'),
    ];

    $form['current_status'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Current Lockdown Status'),
    ];

    $form['current_status']['status'] = [
      '#type' => 'item',
      '#title' => $this->t('Status'),
      '#markup' => $enabled
        ? $this->t('<strong>ENABLED</strong><br>Active Storm Centre NID: @nid', ['@nid' => $nid])
        : $this->t('<strong>DISABLED</strong>'),
    ];

    // Preload default entity if present.
    $default_node = NULL;
    if ($nid > 0) {
      $loaded = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
      if ($loaded instanceof NodeInterface) {
        $default_node = $loaded;
      }
    }

    // Show autocomplete only when enabling.
    $form['manual_enable'] = [
      '#type' => 'details',
      '#title' => $this->t('Manual enable (emergency)'),
      '#open' => !$enabled,
      '#states' => [
        'visible' => [
          ':input[name="toggle_action"]' => ['value' => 'enable'],
        ],
      ],
    ];

    $form['manual_enable']['enable_node'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Storm Centre page to allow'),
      '#target_type' => 'node',
      '#default_value' => $default_node,
      '#selection_settings' => [
        'target_bundles' => ['storm_centre'],
      ],
      '#description' => $this->t('Start typing the title of a storm-centre node.'),
      '#required' => FALSE,
    ];

    // Hidden action marker so we know which button state.
    $form['toggle_action'] = [
      '#type' => 'hidden',
      '#value' => $enabled ? 'disable' : 'enable',
    ];

    // Only ONE Save button from ConfigFormBase. Do not add custom save.
    $form['actions'] = $form['actions'] ?? [];

    // Single toggle button.
    $form['actions']['toggle_lockdown'] = [
      '#type' => 'submit',
      '#name' => 'toggle_lockdown',
      '#value' => $enabled ? $this->t('Disable lockdown now') : $this->t('Enable lockdown now'),
      '#submit' => ['::toggleLockdownSubmit'],
      '#limit_validation_errors' => $enabled ? [] : [['manual_enable', 'enable_node']],
      '#weight' => 20,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $trigger = $form_state->getTriggeringElement();
    if (($trigger['#name'] ?? '') !== 'toggle_lockdown') {
      return;
    }

    if ($form_state->getValue('toggle_action') !== 'enable') {
      return;
    }

    $target_id = (int) $form_state->getValue('enable_node');
    if ($target_id <= 0) {
      $form_state->setErrorByName('enable_node', $this->t('Please select a Storm Centre page.'));
      return;
    }

    $node = \Drupal::entityTypeManager()->getStorage('node')->load($target_id);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'storm-centre') {
      $form_state->setErrorByName('enable_node', $this->t('Selected node is not a storm-centre node.'));
      return;
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Default Save configuration button.
    $this->config('site_lockdown.settings')
      ->set('allow_homepage_redirect', (bool) $form_state->getValue('allow_homepage_redirect'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  public function toggleLockdownSubmit(array &$form, FormStateInterface $form_state): void {
    $state = \Drupal::state();
    $enabled = (bool) $state->get('site_lockdown.enabled', FALSE);

    if ($enabled) {
      $state->set('site_lockdown.enabled', FALSE);
      $state->delete('site_lockdown.allowed_nid');
      $this->messenger()->addStatus($this->t('Storm Centre lockdown has been disabled.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    $nid = (int) $form_state->getValue('enable_node');
    $state->set('site_lockdown.enabled', TRUE);
    $state->set('site_lockdown.allowed_nid', $nid);

    $this->messenger()->addStatus($this->t('Storm Centre lockdown enabled. Allowed NID: @nid', ['@nid' => $nid]));
    $form_state->setRebuild(TRUE);
  }

}
