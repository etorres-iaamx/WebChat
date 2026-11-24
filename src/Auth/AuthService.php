<?php

declare(strict_types=1);

namespace Webchat\Auth;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Webchat\App\Config;
use Webchat\App\Database;
use Webchat\App\Token;
use Webchat\App\Uuid;
use Webchat\Runtime\OpenClawProvisioner;
use Webchat\Channels\ChannelIdentityService;
use Webchat\Runtime\HermesProvisioner;

final class AuthService
{
    public function __construct(private readonly Database $database, private readonly Config $config)
    {
    }

    /** @return array{user: array<string,mixed>, session_token: string, verification_code?: string} */
    public function register(array $input): array
    {
        $this->cleanupExpiredSessions();
        $name = trim((string) ($input['name'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $confirmation = (string) ($input['password_confirmation'] ?? '');
        $phone = trim((string) ($input['phone'] ?? ''));
        $countryCode = trim((string) ($input['country_code'] ?? ''));

        if ($name === '' || mb_strlen($name) > 120) {
            throw new RuntimeException('El nombre es obligatorio.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('El correo no es válido.');
        }
        if (strlen($password) < 10 || $password !== $confirmation) {
            throw new RuntimeException('La contraseña debe tener al menos 10 caracteres y coincidir.');
        }

        $existing = $this->findUserByEmail($email);
        if ($existing !== null && (string) $existing['status'] === 'active') {
            throw new UserAlreadyRegisteredException('Este correo ya está registrado. Inicia sesión con esa cuenta.');
        }

        $code = (string) random_int(100000, 999999);
        $user = $this->database->transaction(function (PDO $pdo) use ($existing, $name, $email, $password, $phone, $countryCode, $code): array {
            if ($existing === null) {
                $publicId = Uuid::v4();
                $statement = $pdo->prepare(<<<'SQL'
                    INSERT INTO users (public_id, display_name, email, email_normalized, password_hash, phone, country_code, status)
                    VALUES (:public_id, :display_name, :email, :email_normalized, :password_hash, :phone, :country_code, 'pending_verification')
                    SQL);
                $statement->execute([
                    'public_id' => $publicId,
                    'display_name' => $name,
                    'email' => $email,
                    'email_normalized' => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'phone' => $phone !== '' ? $phone : null,
                    'country_code' => $countryCode !== '' ? $countryCode : null,
                ]);
                $userId = (int) $pdo->lastInsertId();
            } else {
                $userId = (int) $existing['id'];
                $pdo->prepare('UPDATE users SET display_name = :name, password_hash = :password_hash, phone = :phone, country_code = :country_code, status = \'pending_verification\' WHERE id = :id')
                    ->execute(['name' => $name, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'phone' => $phone !== '' ? $phone : null, 'country_code' => $countryCode !== '' ? $countryCode : null, 'id' => $userId]);
                $pdo->prepare('DELETE FROM verification_codes WHERE user_id = :user_id AND purpose = \'email_verification\' AND used_at IS NULL')
                    ->execute(['user_id' => $userId]);
            }

            $pdo->prepare('INSERT INTO verification_codes (user_id, purpose, code_hash, expires_at) VALUES (:user_id, \'email_verification\', :code_hash, :expires_at)')
                ->execute(['user_id' => $userId, 'code_hash' => hash('sha256', $code), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 900)]);
            $pdo->prepare('INSERT INTO audit_events (user_id, event_type, target_type, target_public_id) VALUES (:user_id, \'user.registered\', \'user\', (SELECT public_id FROM users WHERE id = :lookup_id))')
                ->execute(['user_id' => $userId, 'lookup_id' => $userId]);
            return $this->findUserByIdWithPdo($pdo, $userId) ?? throw new RuntimeException('No fue posible crear el usuario.');
        });

        $mailer = new VerificationMailer($this->config);
        $mailer->sendCode($email, $code, 15);

        $session = $this->createSession((int) $user['id']);
        return ['user' => $this->publicUser($user), 'session_token' => $session['token'], 'session_ttl' => $session['ttl'], 'persistent' => false, 'verification_code' => $this->config->bool('APP_ENV_LOCAL_SHOW_CODES', false) ? $code : null];
    }

    /** @return array{user: array<string,mixed>, agent_created: bool, provisioning_pending: bool} */
    public function verify(string $email, string $code): array
    {
        $user = $this->findUserByEmail(strtolower(trim($email)));
        if ($user === null) {
            throw new RuntimeException('El código no es válido.');
        }
        $statement = $this->database->pdo()->prepare('SELECT * FROM verification_codes WHERE user_id = :user_id AND purpose = \'email_verification\' AND used_at IS NULL AND expires_at > UTC_TIMESTAMP(6) ORDER BY id DESC LIMIT 1');
        $statement->execute(['user_id' => $user['id']]);
        $verification = $statement->fetch();
        if (!is_array($verification) || !hash_equals((string) $verification['code_hash'], hash('sha256', trim($code)))) {
            throw new RuntimeException('El código no es válido o expiró.');
        }

        $result = $this->database->transaction(function (PDO $pdo) use ($user, $verification): array {
            $pdo->prepare('UPDATE verification_codes SET used_at = UTC_TIMESTAMP(6) WHERE id = :id')->execute(['id' => $verification['id']]);
            $pdo->prepare('UPDATE users SET status = \'active\', email_verified_at = UTC_TIMESTAMP(6) WHERE id = :id')->execute(['id' => $user['id']]);
            $agent = $this->ensureLogicalAgent($pdo, (int) $user['id'], (string) $user['display_name']);
            return ['user' => $this->findUserByIdWithPdo($pdo, (int) $user['id']) ?? $user, 'agent_created' => $agent];
        });
        $provisioningPending = false;
        try {
            $this->queueProvisioningForUser((int) $user['id']);
        } catch (\Throwable $exception) {
            $provisioningPending = true;
            $this->recordProvisioningFailure((int) $user['id'], $exception);
        }
        try {
            $this->provisionRuntimes((int) $user['id']);
        } catch (\Throwable $exception) {
            $provisioningPending = true;
            $this->recordProvisioningFailure((int) $user['id'], $exception);
        }
        return [
            'user' => $this->publicUser($result['user']),
            'email_verified' => true,
            'agent_created' => $result['agent_created'],
            'provisioning_pending' => $provisioningPending,
        ];
    }

    public function provisionRuntimesForUser(int $userId): void
    {
        try {
            $this->provisionRuntimes($userId);
        } catch (\Throwable $exception) {
            $this->recordProvisioningFailure($userId, $exception);
            throw $exception;
        }
    }

    public function processPendingProvisioningJobs(int $limit = 10): int
    {
        $statement = $this->database->pdo()->prepare('SELECT id, user_id, attempts, correlation_id FROM runtime_provision_jobs WHERE status IN (\'pending\', \'failed\') AND available_at <= UTC_TIMESTAMP(6) ORDER BY id LIMIT :job_limit');
        $statement->bindValue('job_limit', max(1, min(100, $limit)), PDO::PARAM_INT);
        $statement->execute();
        $jobs = $statement->fetchAll();
        $processed = 0;
        foreach ($jobs as $job) {
            $claim = $this->database->pdo()->prepare('UPDATE runtime_provision_jobs SET status = \'running\', attempts = attempts + 1 WHERE id = :id AND status IN (\'pending\', \'failed\')');
            $claim->execute(['id' => $job['id']]);
            if ($claim->rowCount() !== 1) continue;
            try {
                $this->provisionRuntimes((int) $job['user_id']);
                $this->database->pdo()->prepare('UPDATE runtime_provision_jobs SET status = \'completed\', last_error = NULL WHERE id = :id')->execute(['id' => $job['id']]);
            } catch (\Throwable $exception) {
                $this->recordProvisioningFailure((int) $job['user_id'], $exception, (string) $job['correlation_id'], (int) $job['id']);
            }
            $processed++;
        }
        return $processed;
    }

    /** @return array<string, int> */
    public function cleanupExpiredData(): array
    {
        $verificationDays = $this->retentionDays('WEBCHAT_RETENTION_VERIFICATION_DAYS', 30);
        $relayDays = $this->retentionDays('WEBCHAT_RETENTION_RELAY_DAYS', 30);
        $auditDays = $this->retentionDays('WEBCHAT_RETENTION_AUDIT_DAYS', 180);
        $pdo = $this->database->pdo();

        $deleted = [
            'auth_sessions' => $pdo->exec('DELETE FROM auth_sessions WHERE expires_at <= UTC_TIMESTAMP(6)'),
            'web_sessions' => $pdo->exec('DELETE FROM web_sessions WHERE expires_at <= UTC_TIMESTAMP(6)'),
            'verification_codes' => $pdo->exec(sprintf(
                'DELETE FROM verification_codes WHERE (used_at IS NOT NULL AND used_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL %d DAY)) OR (used_at IS NULL AND expires_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL %d DAY))',
                $verificationDays,
                $verificationDays,
            )),
            'relay_requests' => $pdo->exec(sprintf(
                'DELETE FROM relay_requests WHERE created_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL %d DAY)',
                $relayDays,
            )),
            'audit_events' => $pdo->exec(sprintf(
                'DELETE FROM audit_events WHERE created_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL %d DAY)',
                $auditDays,
            )),
        ];

        return array_map(static fn (false|int $count): int => (int) $count, $deleted);
    }

    private function retentionDays(string $key, int $default): int
    {
        return max(1, min(3650, $this->config->int($key, $default)));
    }

    private function provisionRuntimes(int $userId): void
    {
        if ($this->config->bool('OPENCLAW_PROVISIONING_ENABLED', false)) {
            $this->provisionAgent($userId);
            $this->markProvisioningCompleted($userId, 'openclaw');
        }
        if ($this->config->bool('HERMES_PROVISIONING_ENABLED', false)) {
            $this->provisionHermes($userId);
            $this->markProvisioningCompleted($userId, 'hermes');
        }
    }

    private function markProvisioningCompleted(int $userId, string $runtime): void
    {
        $this->database->pdo()->prepare('UPDATE runtime_provision_jobs SET status = \'completed\', last_error = NULL WHERE user_id = :user_id AND runtime = :runtime')
            ->execute(['user_id' => $userId, 'runtime' => $runtime]);
    }

    private function queueProvisioning(PDO $pdo, int $userId): void
    {
        $runtimes = [];
        if ($this->config->bool('OPENCLAW_PROVISIONING_ENABLED', false)) $runtimes[] = 'openclaw';
        if ($this->config->bool('HERMES_PROVISIONING_ENABLED', false)) $runtimes[] = 'hermes';
        foreach ($runtimes as $runtime) {
            $pdo->prepare('INSERT INTO runtime_provision_jobs (user_id, runtime, status, correlation_id) VALUES (:user_id, :runtime, \'pending\', :correlation_id) ON DUPLICATE KEY UPDATE status = IF(status = \'completed\', status, \'pending\'), available_at = UTC_TIMESTAMP(6), last_error = NULL, correlation_id = VALUES(correlation_id)')
                ->execute(['user_id' => $userId, 'runtime' => $runtime, 'correlation_id' => Uuid::v4()]);
        }
    }

    private function queueProvisioningForUser(int $userId): void
    {
        $this->database->transaction(function (PDO $pdo) use ($userId): void {
            $this->queueProvisioning($pdo, $userId);
        });
    }

    private function recordProvisioningFailure(int $userId, \Throwable $exception, ?string $correlationId = null, ?int $jobId = null): void
    {
        $correlationId ??= Uuid::v4();
        $safeError = $this->redactError($exception->getMessage() ?: get_class($exception));
        error_log(sprintf('WebChat provisioning failed correlation_id=%s user_id=%d exception=%s error=%s', $correlationId, $userId, get_class($exception), $safeError));
        try {
            if ($jobId !== null) {
                $attempts = (int) ($this->database->pdo()->query('SELECT attempts FROM runtime_provision_jobs WHERE id = ' . (int) $jobId)->fetchColumn() ?: 1);
                $delay = min(3600, 10 * (2 ** min(8, max(0, $attempts - 1))));
                $this->database->pdo()->prepare('UPDATE runtime_provision_jobs SET status = IF(attempts >= 8, \'failed\', \'pending\'), available_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ' . $delay . ' SECOND), last_error = :last_error WHERE id = :id')
                    ->execute(['last_error' => $safeError, 'id' => $jobId]);
                return;
            }
            $this->database->pdo()->prepare('UPDATE runtime_provision_jobs SET status = \'pending\', available_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 10 SECOND), last_error = :last_error WHERE user_id = :user_id AND status <> \'completed\'')
                ->execute(['last_error' => $safeError, 'user_id' => $userId]);
        } catch (\Throwable $loggingException) {
            error_log(sprintf('WebChat provisioning state update failed correlation_id=%s user_id=%d exception=%s', $correlationId, $userId, get_class($loggingException)));
        }
    }

    private function redactError(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', $message) ?: 'Unknown provisioning failure';
        $message = preg_replace('/(token|password|secret|authorization|code)[=: ]+[^\s,;]+/i', '$1=[redacted]', $message) ?: $message;
        return mb_substr($message, 0, 500);
    }

    private function provisionAgent(int $userId): void
    {
        $statement = $this->database->pdo()->prepare('SELECT la.id, la.public_id, u.display_name, u.email, u.phone, u.country_code FROM logical_agents la INNER JOIN users u ON u.id = la.user_id WHERE la.user_id = :user_id LIMIT 1');
        $statement->execute(['user_id' => $userId]);
        $agent = $statement->fetch();
        if (!is_array($agent)) return;
        $binding = $this->database->pdo()->prepare('SELECT id, external_agent_id, status FROM runtime_bindings WHERE logical_agent_id = :agent_id AND runtime = \'openclaw\' LIMIT 1');
        $binding->execute(['agent_id' => $agent['id']]);
        $existing = $binding->fetch();
        if (is_array($existing) && $existing['external_agent_id']) {
            if (($existing['status'] ?? '') !== 'active') {
                $this->database->pdo()->prepare('UPDATE runtime_bindings SET status = \'active\', last_error = NULL WHERE id = :id')
                    ->execute(['id' => $existing['id']]);
                $this->database->pdo()->prepare('UPDATE logical_agents SET status = \'active\' WHERE id = :id')
                    ->execute(['id' => $agent['id']]);
            }
            return;
        }
        $resource = (new OpenClawProvisioner($this->config))->provision(
            (string) $agent['public_id'], (string) $agent['display_name'], (string) $agent['email'],
            (string) ($agent['phone'] ?? ''), (string) ($agent['country_code'] ?? ''),
        );
        $this->database->pdo()->prepare('INSERT INTO runtime_bindings (logical_agent_id, runtime, external_agent_id, workspace_ref, status) VALUES (:agent_id, \'openclaw\', :external_id, :workspace, \'active\') ON DUPLICATE KEY UPDATE external_agent_id = VALUES(external_agent_id), workspace_ref = VALUES(workspace_ref), status = \'active\'')
            ->execute(['agent_id' => $agent['id'], 'external_id' => $resource['external_agent_id'], 'workspace' => $resource['workspace']]);
        $this->database->pdo()->prepare('UPDATE logical_agents SET status = \'active\' WHERE id = :id')->execute(['id' => $agent['id']]);
    }

    private function provisionHermes(int $userId): void
    {
        $statement = $this->database->pdo()->prepare('SELECT u.public_id, u.display_name, u.email, rb.external_agent_id FROM users u LEFT JOIN runtime_bindings rb ON rb.logical_agent_id = (SELECT id FROM logical_agents WHERE user_id = u.id LIMIT 1) AND rb.runtime = \'hermes\' WHERE u.id = :user_id LIMIT 1');
        $statement->execute(['user_id' => $userId]);
        $user = $statement->fetch();
        if (!is_array($user) || ($user['external_agent_id'] ?? null)) return;
        $resource = (new HermesProvisioner($this->config))->provision((string) $user['public_id'], (string) $user['display_name'], (string) $user['email']);
        $this->database->pdo()->prepare('INSERT INTO runtime_bindings (logical_agent_id, runtime, external_agent_id, workspace_ref, status) VALUES ((SELECT id FROM logical_agents WHERE user_id = :user_id LIMIT 1), \'hermes\', :external_id, :workspace, \'active\') ON DUPLICATE KEY UPDATE external_agent_id = VALUES(external_agent_id), workspace_ref = VALUES(workspace_ref), status = \'active\'')
            ->execute(['user_id' => $userId, 'external_id' => $resource['external_agent_id'], 'workspace' => $resource['workspace']]);
    }

    /** @return array{user: array<string,mixed>, session_token: string, session_ttl: int, persistent: bool} */
    public function login(string $email, string $password, bool $rememberMe = false): array
    {
        $this->cleanupExpiredSessions();
        $user = $this->findUserByEmail(strtolower(trim($email)));
        if ($user === null || !is_string($user['password_hash']) || !password_verify($password, $user['password_hash']) || $user['status'] !== 'active') {
            throw new RuntimeException('El correo o la contraseña no son correctos.');
        }
        $session = $this->createSession((int) $user['id'], $rememberMe);
        return ['user' => $this->publicUser($user), 'session_token' => $session['token'], 'session_ttl' => $session['ttl'], 'persistent' => $rememberMe];
    }

    /** @return array<string,mixed>|null */
    public function userFromSession(string $token, bool $includeInternalId = false): ?array
    {
        if ($token === '') return null;
        $this->cleanupExpiredSessions();
        $tokenHash = Token::hash($token);
        $statement = $this->database->pdo()->prepare('SELECT u.* FROM auth_sessions s INNER JOIN users u ON u.id = s.user_id WHERE s.token_hash = :token_hash AND s.expires_at > UTC_TIMESTAMP(6) AND ((s.persistent = 1 AND s.last_seen_at > :persistent_idle_cutoff) OR (s.persistent = 0 AND s.last_seen_at > :idle_cutoff)) AND u.status = \'active\' LIMIT 1');
        $statement->execute(['token_hash' => $tokenHash, 'idle_cutoff' => $this->idleCutoff(), 'persistent_idle_cutoff' => $this->persistentIdleCutoff()]);
        $user = $statement->fetch();
        if (is_array($user)) $this->touchSession($tokenHash);
        return is_array($user) ? $this->publicUser($user, $includeInternalId) : null;
    }

    public function userIdFromSession(string $token): ?int
    {
        if ($token === '') return null;
        $this->cleanupExpiredSessions();
        $tokenHash = Token::hash($token);
        $statement = $this->database->pdo()->prepare('SELECT u.id FROM auth_sessions s INNER JOIN users u ON u.id = s.user_id WHERE s.token_hash = :token_hash AND s.expires_at > UTC_TIMESTAMP(6) AND ((s.persistent = 1 AND s.last_seen_at > :persistent_idle_cutoff) OR (s.persistent = 0 AND s.last_seen_at > :idle_cutoff)) AND u.status = \'active\' LIMIT 1');
        $statement->execute(['token_hash' => $tokenHash, 'idle_cutoff' => $this->idleCutoff(), 'persistent_idle_cutoff' => $this->persistentIdleCutoff()]);
        $id = $statement->fetchColumn();
        if ($id !== false) $this->touchSession($tokenHash);
        return $id === false ? null : (int) $id;
    }

    public function logout(string $token): void
    {
        $this->database->pdo()->prepare('DELETE FROM auth_sessions WHERE token_hash = :token_hash')->execute(['token_hash' => Token::hash($token)]);
    }

    public function deleteAccount(int $userId): void
    {
        $user = $this->findUserById($userId);
        if ($user === null) {
            throw new RuntimeException('La cuenta no existe.');
        }

        $statement = $this->database->pdo()->prepare('SELECT rb.external_agent_id FROM logical_agents la LEFT JOIN runtime_bindings rb ON rb.logical_agent_id = la.id AND rb.runtime = \'openclaw\' WHERE la.user_id = :user_id LIMIT 1');
        $statement->execute(['user_id' => $userId]);
        $agentId = $statement->fetchColumn();

        if (is_string($agentId) && $agentId !== '') {
            (new \Webchat\Runtime\OpenClawBindingManager($this->config))->unbindAgentPeers($agentId);
        }
        (new ChannelIdentityService($this->database, $this->config))->unpairUser($userId);

        if (is_string($agentId) && $agentId !== '') {
            (new OpenClawProvisioner($this->config))->delete($agentId);
        }
        $this->database->pdo()->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
    }

    /** @return array<string,mixed> */
    public function changePassword(int $userId, string $currentPassword, string $newPassword, string $sessionToken): array
    {
        if ($userId < 1 || $currentPassword === '' || strlen($newPassword) < 10) {
            throw new RuntimeException('La contraseña nueva debe tener al menos 10 caracteres.');
        }
        if ($currentPassword === $newPassword) {
            throw new RuntimeException('La contraseña nueva debe ser diferente.');
        }

        $user = $this->findUserById($userId);
        if ($user === null || !is_string($user['password_hash']) || !password_verify($currentPassword, $user['password_hash'])) {
            throw new RuntimeException('La contraseña actual no es correcta.');
        }

        $currentTokenHash = Token::hash($sessionToken);
        $this->database->transaction(function (PDO $pdo) use ($userId, $newPassword, $currentTokenHash): void {
            $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id')
                ->execute(['password_hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $userId]);
            $pdo->prepare('DELETE FROM auth_sessions WHERE user_id = :user_id AND token_hash <> :token_hash')
                ->execute(['user_id' => $userId, 'token_hash' => $currentTokenHash]);
            $pdo->prepare('INSERT INTO audit_events (user_id, event_type, target_type, target_public_id) VALUES (:user_id, \'user.password_changed\', \'user\', (SELECT public_id FROM users WHERE id = :lookup_id))')
                ->execute(['user_id' => $userId, 'lookup_id' => $userId]);
        });

        return $this->publicUser($this->findUserById($userId) ?? $user);
    }

    /** @return array<string,mixed> */
    public function updateAvatar(int $userId, array $file): array
    {
        if ($userId < 1 || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Selecciona una imagen válida.');
        }
        $size = (int) ($file['size'] ?? 0);
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($size < 1 || $size > 5 * 1024 * 1024 || !is_uploaded_file($tmp)) {
            throw new RuntimeException('La foto debe pesar como máximo 5 MB.');
        }

        $info = @getimagesize($tmp);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!is_array($info) || (int) ($info[0] ?? 0) > 4096 || (int) ($info[1] ?? 0) > 4096 || !isset($extensions[$mime])) {
            throw new RuntimeException('La foto debe ser JPG, PNG o WebP.');
        }

        $storageRoot = $this->storageRoot();
        $avatarDirectory = $storageRoot . '/avatars';
        if (!is_dir($avatarDirectory) && !mkdir($avatarDirectory, 0750, true) && !is_dir($avatarDirectory)) {
            throw new RuntimeException('No fue posible preparar el almacenamiento de fotos.');
        }
        $relativePath = 'avatars/' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
        $destination = $storageRoot . '/' . $relativePath;
        if (!move_uploaded_file($tmp, $destination)) {
            throw new RuntimeException('No fue posible guardar la foto.');
        }
        chmod($destination, 0640);

        $user = $this->findUserById($userId);
        if ($user === null) {
            @unlink($destination);
            throw new RuntimeException('La cuenta no existe.');
        }
        $this->database->pdo()->prepare('UPDATE users SET avatar_path = :avatar_path WHERE id = :id')
            ->execute(['avatar_path' => $relativePath, 'id' => $userId]);
        $this->deleteStoredAvatar($user['avatar_path'] ?? null, $relativePath);
        return $this->publicUser($this->findUserById($userId) ?? $user);
    }

    /** @return array{path:string,mime:string}|null */
    public function avatarFileForUser(int $userId): ?array
    {
        $user = $this->findUserById($userId);
        $relative = is_array($user) ? (string) ($user['avatar_path'] ?? '') : '';
        if ($relative === '' || !str_starts_with($relative, 'avatars/') || str_contains($relative, '..')) return null;
        $root = realpath($this->storageRoot());
        $path = $root === false ? false : realpath($root . '/' . $relative);
        if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) return null;
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        return in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) ? ['path' => $path, 'mime' => $mime] : null;
    }

    public function cleanupExpiredSessions(): int
    {
        $statement = $this->database->pdo()->prepare('DELETE FROM auth_sessions WHERE expires_at <= UTC_TIMESTAMP(6)');
        $statement->execute();
        return $statement->rowCount();
    }

    /** @return array{token: string, ttl: int} */
    private function createSession(int $userId, bool $persistent = false): array
    {
        $token = Token::random(32);
        $ttl = $this->sessionTtl($persistent);
        $expires = gmdate('Y-m-d H:i:s', time() + $ttl);
        $this->database->pdo()->prepare('INSERT INTO auth_sessions (public_id, user_id, token_hash, persistent, expires_at) VALUES (:public_id, :user_id, :token_hash, :persistent, :expires_at)')
            ->execute(['public_id' => Uuid::v4(), 'user_id' => $userId, 'token_hash' => Token::hash($token), 'persistent' => $persistent ? 1 : 0, 'expires_at' => $expires]);
        return ['token' => $token, 'ttl' => $ttl];
    }

    private function sessionTtl(bool $persistent): int
    {
        $fallback = $persistent ? 2592000 : 43200;
        return max(300, $this->config->int($persistent ? 'AUTH_SESSION_TTL_PERSISTENT' : 'AUTH_SESSION_TTL_SHORT', $fallback));
    }

    private function idleCutoff(): string
    {
        return $this->cutoffForSeconds(max(300, $this->config->int('AUTH_SESSION_IDLE_TTL', 43200)));
    }

    private function persistentIdleCutoff(): string
    {
        return $this->cutoffForSeconds(max(300, $this->config->int('AUTH_SESSION_TTL_PERSISTENT', 2592000)));
    }

    private function cutoffForSeconds(int $seconds): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-' . $seconds . ' seconds')->format('Y-m-d H:i:s.u');
    }

    private function touchSession(string $tokenHash): void
    {
        $this->database->pdo()->prepare('UPDATE auth_sessions SET last_seen_at = UTC_TIMESTAMP(6) WHERE token_hash = :token_hash')->execute(['token_hash' => $tokenHash]);
    }

    private function ensureLogicalAgent(PDO $pdo, int $userId, string $displayName): bool
    {
        $existing = $pdo->prepare('SELECT id FROM logical_agents WHERE user_id = :user_id LIMIT 1');
        $existing->execute(['user_id' => $userId]);
        if ($existing->fetchColumn() !== false) return false;
        $pdo->prepare('INSERT INTO logical_agents (public_id, user_id, display_name, status) VALUES (:public_id, :user_id, :display_name, \'pending\')')
            ->execute(['public_id' => Uuid::v4(), 'user_id' => $userId, 'display_name' => 'Agente de ' . $displayName]);
        return true;
    }

    /** @return array<string,mixed>|null */
    private function findUserByEmail(string $email): ?array
    {
        $statement = $this->database->pdo()->prepare('SELECT * FROM users WHERE email_normalized = :email LIMIT 1');
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();
        return is_array($user) ? $user : null;
    }

    /** @return array<string,mixed>|null */
    private function findUserById(int $id): ?array
    {
        $statement = $this->database->pdo()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $user = $statement->fetch();
        return is_array($user) ? $user : null;
    }

    private function storageRoot(): string
    {
        $configured = trim((string) $this->config->get('WEBCHAT_STORAGE_PATH', ''));
        return rtrim($configured !== '' ? $configured : dirname(__DIR__, 2) . '/storage', '/');
    }

    private function deleteStoredAvatar(mixed $relativePath, string $replacement): void
    {
        $relative = (string) $relativePath;
        if ($relative === '' || $relative === $replacement || !str_starts_with($relative, 'avatars/') || str_contains($relative, '..')) return;
        $root = realpath($this->storageRoot());
        $path = $root === false ? false : realpath($root . '/' . $relative);
        if ($root !== false && $path !== false && str_starts_with($path, $root . DIRECTORY_SEPARATOR) && is_file($path)) @unlink($path);
    }

    /** @return array<string,mixed>|null */
    private function findUserByIdWithPdo(PDO $pdo, int $id): ?array
    {
        $statement = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $user = $statement->fetch();
        return is_array($user) ? $user : null;
    }

    /** @param array<string,mixed> $user @return array<string,mixed> */
    private function publicUser(array $user, bool $includeInternalId = false): array
    {
        $public = ['id' => $user['public_id'], 'name' => $user['display_name'], 'email' => $user['email'], 'status' => $user['status'], 'has_avatar' => !empty($user['avatar_path']), 'avatar_version' => $user['updated_at'] ?? null];
        if ($includeInternalId) $public['_database_id'] = (int) $user['id'];
        return $public;
    }
}
