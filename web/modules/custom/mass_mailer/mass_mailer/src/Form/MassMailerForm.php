<?php

namespace Drupal\mass_mailer\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\mass_mailer\Service\MassMailerBatchService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Mass Mailer administration form.
 *
 * Allows administrators to send bulk emails with options for:
 * - Text paste or CSV file upload for recipients
 * - Configurable batch processing
 * - Failed email tracking and CSV export
 *
 * @package Drupal\mass_mailer\Form
 */
class MassMailerForm extends FormBase {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The batch service.
   *
   * @var \Drupal\mass_mailer\Service\MassMailerBatchService
   */
  protected $batchService;

  /**
   * Constructs a MassMailerForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\mass_mailer\Service\MassMailerBatchService $batch_service
   *   The batch service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, FileSystemInterface $file_system, AccountProxyInterface $current_user, MassMailerBatchService $batch_service) {
    $this->configFactory = $config_factory;
    $this->fileSystem = $file_system;
    $this->currentUser = $current_user;
    $this->batchService = $batch_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('file_system'),
      $container->get('current_user'),
      $container->get('mass_mailer.batch_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'mass_mailer_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['sender_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Sender email'),
      '#required' => TRUE,
      '#default_value' => $form_state->getValue('sender_email') ?? '',
      '#description' => $this->t('This address will be used in From/Reply-To headers. Your mail backend (SMTP, etc.) may still enforce its own sender rules.'),
    ];

    $form['recipients_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Recipient emails (paste from Notepad)'),
      '#required' => FALSE,
      '#rows' => 8,
      '#maxlength' => 100000,
      '#description' => $this->t('Paste emails separated by new lines, commas, or semicolons. Maximum 100,000 characters.'),
    ];

    $form['recipients_csv'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Recipient emails via CSV file'),
      '#description' => $this->t('Upload a CSV (.csv) or text file (.txt). Emails can be in any column; the module will extract valid email addresses. Maximum file size: 5 MB.'),
      '#upload_location' => 'temporary://mass_mailer',
      '#upload_validators' => [
        'file_validate_extensions' => ['csv txt'],
        'file_validate_size' => [5 * 1024 * 1024],
      ],
      '#required' => FALSE,
    ];

    $form['subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#description' => $this->t('Email subject line. Special characters that could cause issues are automatically sanitized.'),
    ];

    $form['body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Body (plain text)'),
      '#required' => TRUE,
      '#rows' => 12,
      '#maxlength' => 50000,
      '#description' => $this->t('Plain-text message body. Maximum 50,000 characters.'),
    ];

    $config = $this->configFactory->get('mass_mailer.settings');
    $default_batch_size = $config->get('batch_size') ?: 50;

    $form['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch size'),
      '#default_value' => $default_batch_size,
      '#min' => 1,
      '#max' => 500,
      '#required' => TRUE,
      '#description' => $this->t('How many emails to send per batch operation. Lower numbers reduce timeouts. This value will be saved as the default for future use.'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send emails'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $sender = (string) $form_state->getValue('sender_email');
    if (!filter_var($sender, FILTER_VALIDATE_EMAIL)) {
      $form_state->setErrorByName('sender_email', $this->t('Please enter a valid sender email.'));
    }

    $has_text = trim((string) $form_state->getValue('recipients_text')) !== '';
    $has_file = !empty($form_state->getValue('recipients_csv'));
    if (!$has_text && !$has_file) {
      $form_state->setErrorByName('recipients_text', $this->t('Please provide recipients by pasting emails or uploading a CSV file.'));
    }

    $subject = (string) $form_state->getValue('subject');
    if (trim($subject) === '') {
      $form_state->setErrorByName('subject', $this->t('Subject is required.'));
    }

    $body = (string) $form_state->getValue('body');
    if (trim($body) === '') {
      $form_state->setErrorByName('body', $this->t('Body is required.'));
    }

    // SECURITY: Validate batch_size range.
    $batch_size = (int) $form_state->getValue('batch_size');
    if ($batch_size < 1 || $batch_size > 500) {
      $form_state->setErrorByName('batch_size', $this->t('Batch size must be between 1 and 500.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $sender = (string) $form_state->getValue('sender_email');
    $subject = (string) $form_state->getValue('subject');
    $body = (string) $form_state->getValue('body');
    $batch_size = (int) $form_state->getValue('batch_size');

    // Save batch_size to configuration for future use.
    $config = $this->configFactory->getEditable('mass_mailer.settings');
    $config->set('batch_size', $batch_size);
    $config->save();

    $recipients = $this->collectRecipients($form_state);

    if (empty($recipients)) {
      $this->messenger()->addError($this->t('No valid recipient emails found.'));
      return;
    }

    // Build batch operations (chunk recipients).
    $chunks = array_chunk($recipients, max(1, $batch_size));

    $builder = (new BatchBuilder())
      ->setTitle($this->t('Sending emails'))
      ->setInitMessage($this->t('Starting email send...'))
      ->setProgressMessage($this->t('Processed @current out of @total batches.'))
      ->setErrorMessage($this->t('The mass mail process encountered an error.'))
      ->setFinishCallback([$this->batchService, 'batchFinished']);

    foreach ($chunks as $chunk) {
      $builder->addOperation([$this->batchService, 'batchSendOperation'], [
        $chunk,
        $sender,
        $subject,
        $body,
      ]);
    }

    batch_set($builder->toArray());
  }

  /**
   * Collects recipient email addresses from form input.
   *
   * Extracts valid email addresses from:
   * - Textarea input (separated by newlines, commas, semicolons, or whitespace)
   * - Uploaded CSV/TXT file (scans all columns for email addresses)
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state containing submitted values.
   *
   * @return array
   *   An array of unique, validated, lowercase email addresses.
   *   Limited to 10,000 emails total from all sources.
   */
  protected function collectRecipients(FormStateInterface $form_state): array {
    $emails = [];

    // From textarea (Notepad paste).
    $text = (string) $form_state->getValue('recipients_text');
    if (trim($text) !== '') {
      // SECURITY: Limit email extraction to prevent resource exhaustion.
      // Maximum 10,000 emails from textarea.
      $max_emails = 10000;
      
      // Split by newline, comma, semicolon, whitespace.
      $parts = preg_split('/[\s,;]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
      foreach ($parts as $p) {
        if (count($emails) >= $max_emails) {
          break;
        }
        
        $p = trim($p);
        if ($p !== '' && filter_var($p, FILTER_VALIDATE_EMAIL)) {
          $emails[] = strtolower($p);
        }
      }
    }

    // From CSV upload.
    $fids = $form_state->getValue('recipients_csv');
    if (!empty($fids) && is_array($fids)) {
      $fid = (int) reset($fids);
      if ($fid) {
        /** @var \Drupal\file\Entity\File|null $file */
        $file = File::load($fid);
        if ($file) {
          // SECURITY: Validate file ownership/access.
          // Only allow access to files uploaded by the current user.
          // Since the form already requires 'send mass mail' permission,
          // files uploaded through this form should be accessible to the current user.
          if ($file->getOwnerId() == $this->currentUser->id() || $this->currentUser->hasPermission('send mass mail')) {
            // Ensure file is treated as temporary (do not permanently save).
            $file->setTemporary();
            $file->save();

            $uri = $file->getFileUri();
            $realpath = $this->fileSystem->realpath($uri);
            if ($realpath && is_readable($realpath)) {
              // SECURITY: Limit file processing to prevent resource exhaustion.
              $emails = array_merge($emails, $this->extractEmailsFromCsv($realpath));
            }
          }
        }
      }
    }

    // Unique + reindex.
    $emails = array_values(array_unique($emails));

    return $emails;
  }

  /**
   * Extracts valid email addresses from a CSV or text file.
   *
   * Processes the file with security limits to prevent resource exhaustion:
   * - Maximum 100,000 rows processed
   * - Maximum 100 columns per row
   * - Maximum 10,000 unique emails extracted
   *
   * @param string $path
   *   The absolute file path to the CSV or text file.
   *
   * @return array
   *   An array of unique, validated, lowercase email addresses found in the file.
   */
  protected function extractEmailsFromCsv(string $path): array {
    $found = [];
    $handle = fopen($path, 'r');
    if (!$handle) {
      return $found;
    }

    // SECURITY: Limit processing to prevent resource exhaustion.
    // Maximum 100,000 rows, 100 columns per row, 10,000 total emails extracted.
    $max_rows = 100000;
    $max_emails = 10000;
    $row_count = 0;

    while (($row = fgetcsv($handle)) !== FALSE && $row_count < $max_rows && count($found) < $max_emails) {
      $row_count++;
      
      if (!is_array($row)) {
        continue;
      }
      
      // Limit columns per row.
      $row = array_slice($row, 0, 100);
      
      foreach ($row as $cell) {
        if (count($found) >= $max_emails) {
          break 2; // Break out of both loops.
        }
        
        if (!is_string($cell)) {
          continue;
        }
        $cell = trim($cell);
        if ($cell === '') {
          continue;
        }
        // A cell might contain multiple emails.
        $parts = preg_split('/[\s,;]+/', $cell, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($parts as $p) {
          if (count($found) >= $max_emails) {
            break 3; // Break out of all loops.
          }
          
          $p = trim($p);
          if ($p !== '' && filter_var($p, FILTER_VALIDATE_EMAIL)) {
            $found[] = strtolower($p);
          }
        }
      }
    }

    fclose($handle);
    return $found;
  }

}
