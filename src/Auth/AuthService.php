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

final class AuthService
{
    public function __construct(private readonly Database $database, private readonly Config $config)
    {
    }

    /** @return array{user: array<string,mixed>, session_token: string, verification_code?: string} */
    public function register(array $input): array
    {
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
            throw new RuntimeException('No fue posible completar el registro.');
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

        return ['user' => $this->publicUser($user), 'session_token' => $this->createSession((int) $user['id']), 'verification_code' => $this->config->bool('APP_ENV_LOCAL_SHOW_CODES', false) ? $code : null];
    }

    /** @return array{user: array<string,mixed>, agent_created: bool} */
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
        if ($this->config->bool('OPENCLAW_PROVISIONING_ENABLED', false)) {
            $this->provisionAgent((int) $user['id']);
        }
        return ['user' => $this->publicUser($result['user']), 'agent_created' => $result['agent_created']];
    }

    private function provisionAgent(int $userId): void
    {
        $statement = $this->database->pdo()->prepare('SELECT la.id, la.public_id FROM logical_agents la WHERE la.user_id = :user_id LIMIT 1');
        $statement->execute(['user_id' => $userId]);
        $agent = $statement->fetch();
        if (!is_array($agent)) return;
        $binding = $this->database->pdo()->prepare('SELECT id, external_agent_id FROM runtime_bindings WHERE logical_agent_id = :agent_id AND runtime = \'openclaw\' LIMIT 1');
        $binding->execute(['agent_id' => $agent['id']]);
        $existing = $binding->fetch();
        if (is_array($existing) && $existing['external_agent_id']) return;
        $resource = (new OpenClawProvisioner($this->config))->provision((string) $agent['public_id']);
        $this->database->pdo()->prepare('INSERT INTO runtime_bindings (logical_agent_id, runtime, external_agent_id, workspace_ref, status) VALUES (:agent_id, \'openclaw\', :external_id, :workspace, \'active\') ON DUPLICATE KEY UPDATE external_agent_id = VALUES(external_agent_id), workspace_ref = VALUES(workspace_ref), status = \'active\'')
            ->execute(['agent_id' => $agent['id'], 'external_id' => $resource['external_agent_id'], 'workspace' => $resource['workspace']]);
        $this->database->pdo()->prepare('UPDATE logical_agents SET status = \'active\' WHERE id = :id')->execute(['id' => $agent['id']]);
    }

    /** @return array{user: array<string,mixed>, session_token: string} */
    public function login(string $email, string $password): array
    {
        $user = $this->findUserByEmail(strtolower(trim($email)));
        if ($user === null || !is_string($user['password_hash']) || !password_verify($password, $user['password_hash']) || $user['status'] !== 'active') {
            throw new RuntimeException('El correo o la contraseña no son correctos.');
        }
        return ['user' => $this->publicUser($user), 'session_token' => $this->createSession((int) $user['id'])];
    }

    /** @return array<string,mixed>|null */
    public function userFromSession(string $token): ?array
    {
        if ($token === '') return null;
        $statement = $this->database->pdo()->prepare('SELECT u.* FROM auth_sessions s INNER JOIN users u ON u.id = s.user_id WHERE s.token_hash = :token_hash AND s.expires_at > UTC_TIMESTAMP(6) AND u.status = \'active\' LIMIT 1');
        $statement->execute(['token_hash' => Token::hash($token)]);
        $user = $statement->fetch();
        return is_array($user) ? $this->publicUser($user) : null;
    }

    public function userIdFromSession(string $token): ?int
    {
        if ($token === '') return null;
        $statement = $this->database->pdo()->prepare('SELECT u.id FROM auth_sessions s INNER JOIN users u ON u.id = s.user_id WHERE s.token_hash = :token_hash AND s.expires_at > UTC_TIMESTAMP(6) AND u.status = \'active\' LIMIT 1');
        $statement->execute(['token_hash' => Token::hash($token)]);
        $id = $statement->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function logout(string $token): void
    {
        $this->database->pdo()->prepare('DELETE FROM auth_sessions WHERE token_hash = :token_hash')->execute(['token_hash' => Token::hash($token)]);
    }

    private function createSession(int $userId): string
    {
        $token = Token::random(32);
        $expires = gmdate('Y-m-d H:i:s', time() + $this->config->int('AUTH_SESSION_TTL', 2592000));
        $this->database->pdo()->prepare('INSERT INTO auth_sessions (public_id, user_id, token_hash, expires_at) VALUES (:public_id, :user_id, :token_hash, :expires_at)')
            ->execute(['public_id' => Uuid::v4(), 'user_id' => $userId, 'token_hash' => Token::hash($token), 'expires_at' => $expires]);
        return $token;
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
    private function findUserByIdWithPdo(PDO $pdo, int $id): ?array
    {
        $statement = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $user = $statement->fetch();
        return is_array($user) ? $user : null;
    }

    /** @param array<string,mixed> $user @return array<string,mixed> */
    private function publicUser(array $user): array
    {
        return ['id' => $user['public_id'], 'name' => $user['display_name'], 'email' => $user['email'], 'status' => $user['status']];
    }
}
