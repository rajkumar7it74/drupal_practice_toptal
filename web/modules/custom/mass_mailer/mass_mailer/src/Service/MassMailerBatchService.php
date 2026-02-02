<?php

namespace Drupal\mass_mailer\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for handling mass mailer batch operations.
 *
 * Handles the batch processing of email sending, including:
 * - Sending emails in chunks
 * - Tracking sent and failed emails
 * - Generating CSV files for failed emails
 * - Displaying batch completion messages
 *
 * @package Drupal\mass_mailer\Service
 */
class MassMailerBatchService {

  use StringTranslationTrait;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Constructs a MassMailerBatchService object.
   *
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   */
  public function __construct(MailManagerInterface $mail_manager, LanguageManagerInterface $language_manager, LoggerChannelFactoryInterface $logger_factory, MessengerInterface $messenger, FileSystemInterface $file_system) {
    $this->mailManager = $mail_manager;
    $this->languageManager = $language_manager;
    $this->logger = $logger_factory->get('mass_mailer');
    $this->messenger = $messenger;
    $this->fileSystem = $file_system;
  }

  /**
   * Batch operation callback: sends a chunk of emails.
   *
   * Processes a chunk of recipient email addresses and attempts to send
   * each email. Tracks successful sends and failures, storing failed
   * email addresses for later export.
   *
   * @param array $recipients
   *   Array of recipient email addresses to send to.
   * @param string $sender
   *   The sender's email address (validated and sanitized).
   * @param string $subject
   *   The email subject line (sanitized).
   * @param string $body
   *   The plain text email body.
   * @param array &$context
   *   The batch context array (passed by reference).
   *   Stores results in $context['results']:
   *   - 'sent': Count of successfully sent emails
   *   - 'failed': Count of failed emails
   *   - 'failed_emails': Array of failed email addresses
   */
  public function batchSendOperation(array $recipients, string $sender, string $subject, string $body, array &$context): void {
    $sent = 0;
    $failed_emails = [];

    // Initialize failed_emails array if not present.
    if (!isset($context['results']['failed_emails'])) {
      $context['results']['failed_emails'] = [];
    }

    foreach ($recipients as $to) {
      $params = [
        'sender_email' => $sender,
        'subject' => $subject,
        'body' => $body,
      ];

      $langcode = $this->languageManager->getDefaultLanguage()->getId();
      $result = $this->mailManager->mail('mass_mailer', 'bulk_send', $to, $langcode, $params, $sender, TRUE);

      if (!empty($result['result'])) {
        $sent++;
      }
      else {
        $failed_emails[] = $to;
        $this->logger->warning('Failed to send email to %to', ['%to' => $to]);
      }
    }

    $context['results']['sent'] = ($context['results']['sent'] ?? 0) + $sent;
    $context['results']['failed'] = ($context['results']['failed'] ?? 0) + count($failed_emails);
    $context['results']['failed_emails'] = array_merge($context['results']['failed_emails'] ?? [], $failed_emails);
  }

  /**
   * Batch finished callback.
   *
   * Called after all batch operations complete. Displays success/failure
   * messages and generates a CSV file of failed email addresses if any
   * emails failed to send.
   *
   * @param bool $success
   *   Whether the batch completed successfully without fatal errors.
   * @param array $results
   *   Array of results from batch operations, containing:
   *   - 'sent': Total count of successfully sent emails
   *   - 'failed': Total count of failed emails
   *   - 'failed_emails': Array of email addresses that failed to send
   * @param array $operations
   *   Array of operations that were processed (for reference).
   */
  public function batchFinished(bool $success, array $results, array $operations): void {
    $failed_emails = $results['failed_emails'] ?? [];

    if ($success) {
      $sent = $results['sent'] ?? 0;
      $failed = $results['failed'] ?? 0;
      $this->messenger->addStatus($this->formatPlural($sent, 'Sent 1 email.', 'Sent @count emails.'));

      if ($failed > 0 && !empty($failed_emails)) {
        // Generate CSV file with failed emails.
        $csv_filepath = $this->generateFailedEmailsCsv($failed_emails);

        if ($csv_filepath && file_exists($csv_filepath)) {
          $filename = basename($csv_filepath);
          $url = \Drupal\Core\Url::fromRoute('mass_mailer.download_failed_csv', [
            'file' => $filename,
          ])->toString();

          // SECURITY: Use proper URL escaping to prevent XSS.
          // The URL is already generated via Url::fromRoute() which is safe.
          // Using :url placeholder follows Drupal conventions for URLs in translations.
          $this->messenger->addWarning($this->formatPlural(
            $failed,
            '1 email failed. <a href=":url">Download CSV with failed email addresses</a>',
            '@count emails failed. <a href=":url">Download CSV with failed email addresses</a>',
            [':url' => $url]
          ));
        }
        else {
          $this->messenger->addWarning($this->formatPlural($failed, '1 email failed (see logs).', '@count emails failed (see logs).'));
        }
      }
    }
    else {
      $this->messenger->addError($this->t('Finished with an error. Some emails may not have been sent.'));
    }
  }

  /**
   * Generates a CSV file containing failed email addresses.
   *
   * Creates a CSV file in the temporary file system with a unique filename.
   * The file contains a header row and one email address per row.
   *
   * @param array $failed_emails
   *   Array of email addresses that failed to send.
   *
   * @return string|false
   *   The absolute file path to the generated CSV file on success,
   *   or FALSE if file creation fails or input is empty.
   *   File is stored in: temporary://mass_mailer/
   */
  protected function generateFailedEmailsCsv(array $failed_emails): string|false {
    if (empty($failed_emails)) {
      return FALSE;
    }

    $temp_directory = $this->fileSystem->realpath('temporary://');

    if (!$temp_directory) {
      return FALSE;
    }

    // Ensure mass_mailer directory exists.
    $mass_mailer_dir = $temp_directory . '/mass_mailer';
    if (!is_dir($mass_mailer_dir)) {
      $this->fileSystem->mkdir($mass_mailer_dir, 0775, TRUE);
    }

    // Create unique filename.
    $filename = 'failed_emails_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.csv';
    $filepath = $mass_mailer_dir . '/' . $filename;

    // Write CSV file.
    $handle = fopen($filepath, 'w');
    if (!$handle) {
      return FALSE;
    }

    // Write header.
    fputcsv($handle, ['Failed Email Address']);

    // Write failed email addresses.
    foreach ($failed_emails as $email) {
      fputcsv($handle, [$email]);
    }

    fclose($handle);

    return $filepath;
  }

}
