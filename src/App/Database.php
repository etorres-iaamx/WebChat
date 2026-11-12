<?php

declare(strict_types=1);

namespace Webchat\App;

use PDO;

final class Database
{
    private PDO $pdo;

    public function __construct(Config $config)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config->required('DB_HOST'),
            $config->int('DB_PORT', 3306),
            $config->required('DB_DATABASE'),
        );

        $this->pdo = new PDO($dsn, $config->required('DB_USERNAME'), (string) $config->get('DB_PASSWORD', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->pdo->exec("SET time_zone = '+00:00'");
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @template T */
    /** @param callable(PDO): T $callback @return T */
    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback($this->pdo);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
