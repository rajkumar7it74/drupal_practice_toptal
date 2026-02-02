<?php

namespace Drupal\site_lockdown\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class SiteLockdownSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'site_lockdown_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['site_lockdown.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('site_lockdown.settings');
    $state = \Drupal::state();

    $form['allow_homepage_redirect'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow homepage redirect to Storm Centre page'),
      '#default_value' => (bool) ($config->get('allow_homepage_redirect') ?? TRUE),
      '#description' => $this->t(
        'If enabled, the homepage (/) will redirect to the active Storm Centre page during lockdown.'
      ),
    ];

    $enabled = (bool) $state->get('site_lockdown.enabled', FALSE);
    $nid = (int) $state->get('site_lockdown.allowed_nid', 0);

    $form['current_status'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Current Lockdown Status'),
    ];

    $form['current_status']['status'] = [
      '#markup' => $enabled
        ? $this->t('<strong>Status:</strong> ENABLED<br><strong>Active Storm Centre NID:</strong> @nid', ['@nid' => $nid])
        : $this->t('<strong>Status:</strong> DISABLED'),
    ];

    $form['actions'] = ['#type' => 'actions'];

    $form['actions']['save'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save configuration'),
    ];

    $form['actions']['disable_now'] = [
      '#type' => 'submit',
      '#value' => $this->t('Disable lockdown now'),
      '#submit' => ['::disableLockdownSubmit'],
      '#limit_validation_errors' => [],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('site_lockdown.settings')
      ->set('allow_homepage_redirect', (bool) $form_state->getValue('allow_homepage_redirect'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Disable lockdown immediately.
   */
  public function disableLockdownSubmit(array &$form, FormStateInterface $form_state): void {
    $state = \Drupal::state();
    $state->set('site_lockdown.enabled', FALSE);
    $state->delete('site_lockdown.allowed_nid');

    $this->messenger()->addStatus($this->t('Storm Centre lockdown has been disabled.'));
    $form_state->setRebuild(TRUE);
  }

}
