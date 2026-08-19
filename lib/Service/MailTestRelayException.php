<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Service;

/**
 * Raised by {@see SmtpMailTestRelay} when the outgoing SMTP session
 * fails at a specific protocol stage. The stage label ends up in the
 * operator diagnostic message so the hoster can jump straight to the
 * offending config item (e.g. "auth" → check user/password).
 */
class MailTestRelayException extends \RuntimeException {

    public const STAGE_CONFIG    = 'config';
    public const STAGE_CONNECT  = 'connect';
    public const STAGE_GREETING = 'greeting';
    public const STAGE_EHLO     = 'ehlo';
    public const STAGE_STARTTLS = 'starttls';
    public const STAGE_AUTH     = 'auth';
    public const STAGE_FROM     = 'mail-from';
    public const STAGE_RCPT     = 'rcpt-to';
    public const STAGE_DATA     = 'data';
    public const STAGE_QUIT     = 'quit';

    public function __construct(
        string $message,
        public readonly string $stage,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
