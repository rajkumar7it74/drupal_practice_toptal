# Mass Mailer

A Drupal module for sending bulk emails from an administrative form. Supports recipient input via text paste or CSV file upload, with batch processing and failed email tracking.

## Requirements

- Drupal 10 or 11
- PHP 8.1 or higher
- File module (core)

## Installation

1. Download and install the module using one of the following methods:

   **Using Composer:**
   ```bash
   composer require drupal/mass_mailer
   ```

   **Manual installation:**
   - Download the module from the [project page](https://www.drupal.org/project/mass_mailer)
   - Extract the module to `modules/contrib/mass_mailer` (or `modules/custom/mass_mailer` for custom installation)
   - Enable the module via Drush:
     ```bash
     drush en mass_mailer -y
     ```
   - Or enable via the Drupal admin UI: Extend → search "Mass Mailer" → Install

2. Grant the "Send mass mail" permission to appropriate user roles at:
   `/admin/people/permissions`

## Configuration

1. Navigate to the Mass Mailer form:
   `/admin/config/system/mass-mailer`
   
   Or via the admin menu:
   Configuration → System → Mass Mailer

2. Configure default batch size:
   - The batch size setting is saved automatically after first use
   - Default: 50 emails per batch
   - Range: 1-500 emails per batch
   - Lower values reduce timeout risk for large email lists

## Usage

### Sending Bulk Emails

1. Go to `/admin/config/system/mass-mailer`

2. Fill in the form:
   - **Sender email**: The email address used in From/Reply-To headers
   - **Recipients**: Either:
     - Paste email addresses (separated by newlines, commas, or semicolons) in the textarea, OR
     - Upload a CSV/TXT file containing email addresses (emails will be extracted from any column)
   - **Subject**: Email subject line
   - **Body**: Plain text email body
   - **Batch size**: Number of emails to send per batch operation

3. Click "Send emails"

4. The batch process will:
   - Process emails in chunks based on batch size
   - Show progress during sending
   - Display results: number sent, number failed
   - Provide a download link for failed email addresses (CSV format)

### Failed Emails

If any emails fail to send:
- A warning message will appear after batch completion
- Click the "Download CSV with failed email addresses" link
- The CSV file contains all email addresses that failed to send
- Review the logs at `/admin/reports/dblog` for detailed error messages

## Features

- **Flexible Recipient Input**: Accept emails via text paste or CSV file upload
- **Batch Processing**: Prevents timeouts by processing emails in configurable batches
- **Email Validation**: Automatically validates and deduplicates email addresses
- **Failed Email Tracking**: Download CSV file of failed email addresses for retry
- **Security**: 
  - Email header injection prevention
  - File upload validation
  - Resource exhaustion protection
  - Proper access controls
- **Dependency Injection**: Follows Drupal best practices for maintainability and testability

## Permissions

- **Send mass mail**: Access the Mass Mailer form and send bulk emails

## Technical Details

### Batch Processing

- Emails are processed in batches to prevent PHP execution timeouts
- Batch size is configurable (1-500, default 50)
- Progress is displayed during batch operations
- Failed emails are tracked individually

### Email Extraction

- From textarea: Extracts emails separated by newlines, commas, semicolons, or whitespace
- From CSV: Scans all columns for valid email addresses
- Limits:
  - Maximum 10,000 emails from textarea
  - Maximum 10,000 emails from CSV file
  - Maximum 100,000 rows processed from CSV
  - Maximum 100 columns per CSV row

### File Upload

- Supported formats: CSV, TXT
- Maximum file size: 5 MB
- Files are stored temporarily and automatically cleaned up
- Email addresses can be in any column of the CSV

### Security Considerations

- Email header injection prevention
- File ownership validation
- Input size limits to prevent resource exhaustion
- XSS protection in user-facing messages
- Content-Disposition header sanitization

## Mail Backend Configuration

This module uses Drupal's Mail API, which works with:
- Core PHP mail()
- SMTP modules (recommended for production)
- Other mail backend modules

For reliable delivery and to avoid spam filtering, it is **strongly recommended** to use an SMTP module:
- [SMTP Authentication Support](https://www.drupal.org/project/smtp)
- [Swift Mailer](https://www.drupal.org/project/swiftmailer)

Configure your mail backend at:
`/admin/config/system/smtp` (if using SMTP module)

## Troubleshooting

### Emails not sending

1. Check Drupal logs: `/admin/reports/dblog`
2. Verify mail backend configuration
3. Test with a single recipient first
4. Check PHP mail configuration
5. Review SMTP module settings (if used)

### Batch timeout errors

- Reduce batch size in the form
- Increase PHP `max_execution_time`
- Consider using a queue-based approach for very large lists

### CSV file not processing

- Ensure file is valid CSV or TXT format
- Check file size (max 5 MB)
- Verify file contains valid email addresses
- Review file permissions

## API Documentation

### Hooks

#### `hook_mail()`

The module implements `mass_mailer_mail()` to handle email formatting:
- Key: `bulk_send`
- Parameters:
  - `subject`: Email subject (sanitized)
  - `body`: Plain text email body
  - `sender_email`: Sender email address (validated and sanitized)

### Services

#### `mass_mailer.batch_service`

Service class for handling batch operations:
- `batchSendOperation()`: Sends a chunk of emails
- `batchFinished()`: Callback after batch completion
- `generateFailedEmailsCsv()`: Creates CSV file of failed emails

## Maintainers

- Current maintainers: [Add your name/username]
- Seeking co-maintainers: Yes

## Support

- Issue queue: https://www.drupal.org/project/issues/mass_mailer
- Documentation: See this README
- Security issues: Follow [Drupal security reporting process](https://www.drupal.org/security-team/report-issue)

## License

GPL-2.0-or-later

## Development

### Running tests

```bash
# Run PHPUnit tests
vendor/bin/phpunit modules/contrib/mass_mailer/tests

# Run PHPCS coding standards
vendor/bin/phpcs --standard=vendor/drupal/coder/coder_sniffer/Drupal modules/contrib/mass_mailer
```

### Contributing

Contributions are welcome! Please:
1. Follow [Drupal coding standards](https://www.drupal.org/docs/develop/standards)
2. Write tests for new features
3. Update documentation as needed
4. Submit patches via the issue queue

## Changelog

### 8.x-1.0
- Initial release
- Basic bulk email functionality
- CSV upload support
- Batch processing
- Failed email tracking
- Dependency injection implementation
- Security hardening
