<?php

declare(strict_types=1);

namespace Webchat\Runtime;

use RuntimeException;

final class LocalCommand
{
    /** @return array{stdout:string,stderr:string,returncode:int} */
    public static function run(array $command, ?string $stdin = null, float $timeout = 30.0): array
    {
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('No fue posible iniciar el comando de runtime.');
        if ($stdin !== null) { fwrite($pipes[0], $stdin); }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = $stderr = '';
        $started = microtime(true);
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) break;
            if (microtime(true) - $started > $timeout) {
                proc_terminate($process);
                fclose($pipes[1]); fclose($pipes[2]);
                proc_close($process);
                throw new RuntimeException('El comando de runtime excedió el tiempo límite.');
            }
            usleep(10000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $returncode = proc_close($process);
        return ['stdout' => $stdout, 'stderr' => $stderr, 'returncode' => $returncode];
    }

    /** @return mixed */
    public static function json(array $command, ?string $stdin = null): mixed
    {
        $result = self::run($command, $stdin);
        if ($result['returncode'] !== 0) throw new RuntimeException(trim($result['stderr']) ?: 'El comando de runtime falló.');
        $decoded = json_decode($result['stdout'], true);
        if (json_last_error() !== JSON_ERROR_NONE) throw new RuntimeException('El comando de runtime devolvió JSON inválido.');
        return $decoded;
    }
}
