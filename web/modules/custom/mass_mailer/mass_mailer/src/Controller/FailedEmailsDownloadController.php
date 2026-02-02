<?php

namespace Drupal\mass_mailer\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for downloading failed emails CSV files.
 *
 * Provides secure download of CSV files containing email addresses
 * that failed to send during bulk email operations.
 *
 * @package Drupal\mass_mailer\Controller
 */
class FailedEmailsDownloadController extends ControllerBase {

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Constructs a FailedEmailsDownloadController object.
   *
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   */
  public function __construct(FileSystemInterface $file_system) {
    $this->fileSystem = $file_system;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('file_system')
    );
  }

  /**
   * Downloads a failed emails CSV file.
   *
   * Provides secure file download with validation:
   * - Validates filename matches expected pattern
   * - Restricts access to files in the temporary://mass_mailer directory
   * - Sanitizes filename for Content-Disposition header
   * - Sets appropriate security headers
   *
   * @param string $file
   *   The filename of the CSV file to download. Must match pattern:
   *   failed_emails_YYYY-MM-DD_HH-ii-ss_[unique-id].csv
   *
   * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
   *   A BinaryFileResponse with the CSV file as attachment.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   If the file doesn't exist, is not readable, or filename is invalid.
   */
  public function download(string $file): BinaryFileResponse {
    // Security: Only allow CSV files from our temp directory.
    $file = basename($file);
    if (!preg_match('/^failed_emails_.*\.csv$/', $file)) {
      throw new NotFoundHttpException();
    }

    $temp_directory = $this->fileSystem->realpath('temporary://');
    
    if (!$temp_directory) {
      throw new NotFoundHttpException();
    }

    $filepath = $temp_directory . '/mass_mailer/' . $file;

    if (!file_exists($filepath) || !is_readable($filepath)) {
      throw new NotFoundHttpException();
    }

    // SECURITY: Sanitize filename for Content-Disposition header to prevent header injection.
    // Remove any characters that could be used for header injection (newlines, quotes, etc.).
    $safe_filename = preg_replace('/[^\w\-_\.]/', '_', $file);
    // Ensure it still matches our expected pattern after sanitization.
    if (!preg_match('/^failed_emails_.*\.csv$/', $safe_filename)) {
      $safe_filename = 'failed_emails.csv';
    }

    // Create a binary file response.
    $response = new BinaryFileResponse($filepath);
    $response->setContentDisposition(
      ResponseHeaderBag::DISPOSITION_ATTACHMENT,
      $safe_filename
    );
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    
    // SECURITY: Add security headers to prevent XSS if file is opened in browser.
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    $response->headers->set('X-Download-Options', 'noopen');

    return $response;
  }

}
