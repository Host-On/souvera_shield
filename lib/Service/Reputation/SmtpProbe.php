<?php
declare(strict_types=1);

namespace OCA\SouveraShield\Service\Reputation;

/**
 * Minimal SMTP banner/EHLO probe – no mail is ever sent.
 *
 * Used to verify, with *real* protocol data:
 *   - the server greets with a proper FQDN banner (HELO identity)
 *   - STARTTLS is advertised (transport encryption available)
 */
class SmtpProbe {

    /**
     * @return array{
     *   reachable: bool,
     *   banner_host: ?string,
     *   starttls: bool,
     *   error: ?string
     * }
     */
    public function probe(string $host, int $port = 25, int $timeout = 8): array {
        $result = ['reachable' => false, 'banner_host' => null, 'starttls' => false, 'error' => null];

        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            'tcp://' . $host . ':' . $port,
            $errno,
            $errstr,
            $timeout,
        );
        if ($stream === false) {
            $result['error'] = $errstr !== '' ? $errstr : ('errno ' . $errno);
            return $result;
        }
        stream_set_timeout($stream, $timeout);

        try {
            $banner = $this->readReply($stream);
            if ($banner === null || !str_starts_with($banner, '220')) {
                $result['error'] = 'unexpected greeting: ' . substr((string)$banner, 0, 120);
                return $result;
            }
            $result['reachable'] = true;
            // "220 mail.example.com ESMTP …" → second token is the identity.
            $parts = preg_split('/\s+/', trim($banner));
            if (is_array($parts) && isset($parts[1])) {
                $result['banner_host'] = rtrim($parts[1], '.');
            }

            @fwrite($stream, "EHLO souvera-shield.probe\r\n");
            $ehlo = $this->readReply($stream);
            if ($ehlo !== null && preg_match('/^250[- ]STARTTLS\b/im', $ehlo) === 1) {
                $result['starttls'] = true;
            }
            @fwrite($stream, "QUIT\r\n");
        } finally {
            @fclose($stream);
        }
        return $result;
    }

    /** @param resource $stream */
    private function readReply($stream): ?string {
        $buffer = '';
        while (true) {
            $line = fgets($stream, 1024);
            if ($line === false) {
                return $buffer !== '' ? $buffer : null;
            }
            $buffer .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $buffer;
    }
}
