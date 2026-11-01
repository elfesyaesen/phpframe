<?php

declare(strict_types=1);

namespace App\Api\Services;

use App\Api\Exceptions\DuplicateNameException;
use App\Api\Models\AuthModel;
use App\Api\Models\PasswordResetModel;
use App\Api\Models\UserModel;
use System\Config\AppConfig;
use System\Config\AuthConfig;
use System\Database\Database;
use System\Exceptions\BadRequestException;
use System\Exceptions\InternalServerException;
use System\Exceptions\NotFoundException;
use System\Exceptions\ValidationException;
use System\Security\PasswordHasher;
use System\Translation\Contract\TranslatorInterface;

final class UserService
{
    private const EDITABLE_FIELDS = ['firstname', 'lastname', 'phone'];

    public function __construct(
        private readonly UserModel $users,
        private readonly AuthModel $auth,
        private readonly PasswordResetModel $resets,
        private readonly AuthService $sessions,
        private readonly MailService $mail,
        private readonly PasswordHasher $passwords,
        private readonly Database $database,
        private readonly TranslatorInterface $translator,
        private readonly AppConfig $app,
        private readonly AuthConfig $auth_config,
        private readonly AvatarService $avatars,
    ) {
    }

    /**
     * @param array<string, mixed> $validated
     * @throws ValidationException
     */
    public function register(array $validated): TokenBundle
    {
        $validated['password'] = $this->passwords->hash((string) $validated['password']);

        try {
            $uuid = $this->users->storeUser($validated);
        } catch (DuplicateNameException $e) {
            $key     = $e->field === 'email' ? 'user.email_taken' : 'user.username_taken';
            $message = $this->translator->trans($key);

            throw new ValidationException([$e->field => [$message]], $message, previous: $e);
        }

        $user = $this->auth->getUserByUuid($uuid);

        if ($user === false) {
            throw new InternalServerException($this->translator->trans('user.register_failed'));
        }

        return $this->sessions->startSession($user);
    }

    /**
     * @return array<string, mixed>
     * @throws NotFoundException
     */
    public function profile(string $userUuid): array
    {
        $profile = $this->users->getProfile($userUuid);

        if ($profile === false) {
            throw new NotFoundException($this->translator->trans('user.not_found'));
        }

        $profile['avatar'] = $this->avatars->resource($profile['avatar_media_uuid'] ?? null);
        unset($profile['avatar_media_uuid']);

        return $profile;
    }

    /**
     * @param array<string, mixed> $present
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     * @throws ValidationException
     * @throws NotFoundException
     */
    public function updateProfile(string $userUuid, array $present, array $validated): array
    {
        $set = [];

        foreach (self::EDITABLE_FIELDS as $field) {
            if (array_key_exists($field, $present)) {
                $set[$field] = $validated[$field] ?? null;
            }
        }

        if ($set === []) {
            $message = $this->translator->trans('user.profile.no_changes');

            throw new ValidationException(['profile' => [$message]], $message);
        }

        $this->users->updateProfile($userUuid, $set);

        return $this->profile($userUuid);
    }

    /**
     * @throws BadRequestException
     */
    public function changePassword(string $userUuid, string $currentPassword, string $newPassword): void
    {
        $this->assertPassword($userUuid, $currentPassword);

        $this->database->transaction(function () use ($userUuid, $newPassword): void {
            if (!$this->users->updatePassword($userUuid, $this->passwords->hash($newPassword))) {
                throw new InternalServerException($this->translator->trans('user.password_update_failed'));
            }

            $this->auth->removeRefreshToken($userUuid);
        });
    }

    public function requestPasswordReset(string $email): void
    {
        $user = $this->users->getUserByEmail($email);

        if ($user === false) {
            return;
        }

        $uuid = (string) $user['uuid'];

        $token = bin2hex(random_bytes(32));

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        try {
            $issued = $this->database->transaction(function () use ($uuid, $token, $code): bool {
                $used = $this->resets->attemptsUsed($uuid);
                if ($used >= $this->auth_config->passwordResetMaxAttempts) {
                    return false;
                }

                $this->resets->deleteForUser($uuid);
                $this->resets->store(
                    $uuid,
                    hash('sha256', $token),
                    $this->hashResetCode($code),
                    time() + $this->auth_config->passwordResetExpire,
                    $used
                );

                return true;
            });
        } catch (\Throwable $e) {
            throw new InternalServerException(
                $this->translator->trans('user.password_reset_failed'),
                previous: $e
            );
        }

        if (!$issued) {
            return;
        }

        $url = $this->auth_config->resetLink($this->app, $token);

        $this->mail->sendPasswordResetLink(
            (string) $user['email'],
            $url,
            $code,
            (int) ceil($this->auth_config->passwordResetExpire / 60)
        );
    }

    /**
     * @throws NotFoundException
     */
    public function confirmPasswordReset(string $token, string $newPassword): void
    {
        $invalid = fn(): NotFoundException => new NotFoundException(
            $this->translator->trans('user.reset_token_invalid')
        );

        $record = $this->resets->findValid(
            hash('sha256', $token),
            $this->auth_config->passwordResetMaxAttempts
        );

        if ($record === null) {
            throw $invalid();
        }

        $this->applyReset($record['uuid'], (string) $record['user_uuid'], $newPassword, $invalid);
    }

    /**
     * @throws NotFoundException
     */
    public function confirmPasswordResetWithCode(string $email, string $code, string $newPassword): void
    {
        $invalid = fn(): NotFoundException => new NotFoundException(
            $this->translator->trans('user.reset_code_invalid')
        );

        $user = $this->users->getUserByEmail($email);

        if ($user === false) {
            throw $invalid();
        }

        $userUuid = (string) $user['uuid'];
        $max      = $this->auth_config->passwordResetMaxAttempts;
        $record   = $this->resets->findValidForUser($userUuid, $max);

        if ($record === null || !$this->resets->reserveAttempt($record['uuid'], $max)) {
            throw $invalid();
        }

        if (!hash_equals($record['code_hash'], $this->hashResetCode($code))) {
            throw $invalid();
        }

        $this->applyReset($record['uuid'], $userUuid, $newPassword, $invalid);
    }

    private function applyReset(string $recordUuid, string $userUuid, string $newPassword, \Closure $invalid): void
    {
        $hash = $this->passwords->hash($newPassword);

        $this->database->transaction(function () use ($recordUuid, $userUuid, $hash, $invalid): void {
            if (!$this->resets->consume($recordUuid)) {
                throw $invalid();
            }

            if (!$this->users->updatePassword($userUuid, $hash)) {
                throw new InternalServerException($this->translator->trans('user.password_update_failed'));
            }

            $this->resets->deleteForUser($userUuid);

            $this->auth->removeRefreshToken($userUuid);
        });
    }

    /**
     * @throws BadRequestException
     */
    public function deleteAccount(string $userUuid, string $currentPassword): void
    {
        $this->assertPassword($userUuid, $currentPassword);

        $this->database->transaction(function () use ($userUuid): void {
            if (!$this->users->softDeleteUser($userUuid)) {
                throw new InternalServerException($this->translator->trans('user.delete_failed'));
            }

            $this->auth->removeRefreshToken($userUuid);
        });
    }

    /**
     * @throws BadRequestException
     */
    private function assertPassword(string $userUuid, string $password): void
    {
        $hash = $this->users->getUserPassword($userUuid);

        if ($hash === false || $hash === '' || !$this->passwords->verify($password, $hash)) {
            throw new BadRequestException($this->translator->trans('user.current_password_wrong'));
        }
    }

    private function hashResetCode(string $code): string
    {
        return hash_hmac('sha256', $code, $this->auth_config->secretKey);
    }
}
