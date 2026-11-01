<?php

namespace App\Api\Services;

use System\Config\AppConfig;
use System\Config\AuthConfig;
use App\Api\Models\AuthModel;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use System\Database\Database;
use System\Exceptions\BadRequestException;
use System\Exceptions\NotFoundException;
use System\Exceptions\UnauthorizedException;
use System\Helpers\Uuid;
use System\Http\Request;
use System\Translation\Contract\TranslatorInterface;

class AuthService
{
    public const TOKEN_TYPE_ACCESS  = 'access';
    public const TOKEN_TYPE_REFRESH = 'refresh';

    public function __construct(
        private readonly AuthConfig $auth,
        private readonly AppConfig $app,
        private readonly AuthModel $users,
        private readonly Database $database,
        private readonly TranslatorInterface $translator,
        private readonly Request $request,
    ) {}

    public function user(): array
    {
        $data = $this->validate(self::TOKEN_TYPE_ACCESS);

        if (isset($data['user'])) {
            return $data['user'];
        }

        return [];
    }


    public function uuid(): string|false
    {
        $user = $this->user();
        return $user['uuid'] ?? false;
    }

    public function validate(?string $expectedType = null): array|false
    {
        $token = $this->bearer();

        if ($token === false) {
            return false;
        }

        try {
            $decoded = JWT::decode($token, new Key($this->auth->secretKey, 'HS256'));
            $payload = json_decode(json_encode($decoded), true);
        } catch (\Exception) {
            return false;
        }

        if ($expectedType !== null && ($payload['type'] ?? null) !== $expectedType) {
            return false;
        }

        return $payload;
    }

    public function token(array $users): array
    {
        $now = time();

        $access_token_payload = [
            "iss"  => $this->app->url,
            "aud"  => $this->app->url,
            "iat"  => $now,
            "nbf"  => $now,
            "exp"  => $now + $this->auth->accessTokenExpire,
            "jti"  => Uuid::generate(),
            "type" => self::TOKEN_TYPE_ACCESS,
            "user" => $users,
        ];

        $refresh_token_payload = [
            "iss"  => $this->app->url,
            "aud"  => $this->app->url,
            "iat"  => $now,
            "nbf"  => $now,
            "exp"  => $now + $this->auth->refreshTokenExpire,
            "jti"  => Uuid::generate(),
            "type" => self::TOKEN_TYPE_REFRESH,
            "user" => $users,
        ];

        $data['access_token']  = JWT::encode($access_token_payload, $this->auth->secretKey, 'HS256');
        $data['refresh_token'] = JWT::encode($refresh_token_payload, $this->auth->secretKey, 'HS256');
        $data['expired_at']    = date("Y-m-d H:i:s", $now + $this->auth->accessTokenExpire);
        $data['user']          = $users;

        return $data;
    }

    /**
     * @param array<string, mixed> $user
     */
    public function bundle(array $user): TokenBundle
    {
        $data = $this->token($user);

        return new TokenBundle(
            accessToken:  $data['access_token'],
            refreshToken: $data['refresh_token'],
            expiredAt:    $data['expired_at'],
            user:         $data['user'],
        );
    }

    /**
     * @throws BadRequestException
     */
    public function login(string $email, string $password): TokenBundle
    {
        if ($email === '') {
            throw new BadRequestException($this->translator->trans('auth.email_required'));
        }

        if ($password === '') {
            throw new BadRequestException($this->translator->trans('auth.password_required'));
        }

        $user = $this->users->authenticate($email, $password);

        if ($user === false) {
            throw new BadRequestException($this->translator->trans('auth.invalid_credentials'));
        }

        return $this->startSession($user);
    }

    /**
     * @throws UnauthorizedException
     * @throws NotFoundException
     */
    public function refresh(): TokenBundle
    {
        $payload      = $this->validate(self::TOKEN_TYPE_REFRESH);
        $refreshToken = $this->bearer();
        $userUuid     = $payload['user']['uuid'] ?? null;

        if ($payload === false || $refreshToken === false || $userUuid === null) {
            throw new UnauthorizedException($this->translator->trans('auth.refresh_invalid'));
        }

        $user = $this->users->getUserByUuid($userUuid);

        if ($user === false) {
            throw new NotFoundException($this->translator->trans('user.not_found'));
        }

        $incomingHash = self::hashToken($refreshToken);
        $new          = $this->bundle($user);

        $rotated = $this->users->rotateRefreshToken(
            $userUuid,
            $incomingHash,
            self::hashToken($new->accessToken),
            self::hashToken($new->refreshToken),
        );

        if (!$rotated) {
            if ($this->users->isRefreshTokenReused($userUuid, $incomingHash)) {
                $this->users->removeRefreshToken($userUuid);
            }

            throw new UnauthorizedException($this->translator->trans('auth.refresh_token_invalid'));
        }

        return $new;
    }

    public function logout(string $userUuid): void
    {
        $this->users->removeRefreshToken($userUuid);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function startSession(array $user): TokenBundle
    {
        $bundle = $this->bundle($user);

        $this->database->transaction(function () use ($user, $bundle): void {
            $this->users->replaceToken(
                (string) $user['uuid'],
                self::hashToken($bundle->accessToken),
                self::hashToken($bundle->refreshToken),
            );
        });

        return $bundle;
    }

    public function bearer(): string|false
    {
        return $this->request->token() ?? false;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
