<?php

declare(strict_types=1);

namespace Webchat\Auth;

use RuntimeException;
use Webchat\App\Config;

final class VerificationMailer
{
    public function __construct(private readonly Config $config)
    {
    }

    public function sendCode(string $recipient, string $code, int $minutes): void
    {
        $body = "Hola,\n\nTu código de verificación de WebChat es: {$code}\n\nExpira en {$minutes} minutos.\n\nSi no solicitaste este código, ignora este mensaje.\n";
        if (!$this->config->bool('HIMALAYA_ENABLED', false)) {
            error_log('Himalaya disabled; verification email not sent to ' . $this->mask($recipient));
            return;
        }

        $command = [$this->config->required('HIMALAYA_BINARY'), '--account', $this->config->required('HIMALAYA_ACCOUNT'), 'message', 'send'];
        $process = @proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('No fue posible iniciar Himalaya. Verifica HIMALAYA_BINARY.');
        }
        fwrite($pipes[0], "From: " . $this->config->required('HIMALAYA_FROM') . "\nTo: {$recipient}\nSubject: Código de verificación de WebChat\n\n{$body}");
        fclose($pipes[0]);
        stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) throw new RuntimeException('No fue posible enviar el correo de verificación: ' . trim($error));
    }

    private function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return substr($local, 0, 1) . '***@' . $domain;
    }
}
