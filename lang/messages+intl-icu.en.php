<?php

declare(strict_types=1);

/**
 * English message catalogue.
 *
 * Naming convention and ICU notes: see `messages+intl-icu.tr.php`.
 *
 * PLURALS: `min`/`max`/`between` are polymorphic rules — the same rule checks
 * string length, numeric value or array count (see Min::passes). A message
 * therefore cannot name a unit ("characters"), which is also why no plural
 * form is needed here. ICU was still chosen so that unit-specific, properly
 * pluralised messages can be added later without changing the catalogue
 * format.
 */

return [
    // ── Authentication ──
    'auth.required'              => 'You must sign in to perform this action.',
    'auth.email_required'        => 'E-mail address is required.',
    'auth.password_required'     => 'Password is required.',
    'auth.invalid_credentials'   => 'E-mail or password is incorrect.',
    'auth.refresh_invalid'       => 'The refresh token is invalid.',
    'auth.refresh_token_invalid' => 'The refresh token is invalid or has already been used.',
    'auth.logout_success'        => 'Signed out.',
    'auth.logout_failed'         => 'Could not sign out.',

    // ── User ──
    'user.not_found'              => 'User not found.',
    'user.email_taken'            => 'That e-mail address is already registered.',
    'user.username_taken'         => 'That username is already taken.',
    'user.register_failed'        => 'Registration could not be completed.',
    'user.password_updated'       => 'Your password has been updated.',
    'user.password_update_failed' => 'The password could not be updated.',
    'user.current_password_wrong' => 'Your current password is incorrect.',
    'user.mail_failed'            => 'The e-mail could not be sent.',
    'user.account_deleted'        => 'Your account has been deleted.',
    'user.delete_failed'          => 'The account could not be deleted.',
    'user.profile.no_changes'     => 'No fields were provided to update.',

    // ── Avatar ──
    'user.avatar.required'    => 'Please choose an image file.',
    'user.avatar.not_found'   => 'Avatar not found.',
    'user.avatar.save_failed' => 'The avatar could not be saved.',

    // ── Role ──
    'role.not_found'           => 'Role not found.',
    'role.name_taken'          => 'That role name is already in use.',
    'role.updated'             => 'Role updated.',
    'role.deleted'             => 'Role deleted.',
    'role.in_use'              => 'The role is assigned to one or more users and cannot be deleted.',
    'role.assigned'            => 'Role assigned.',
    'role.assign_failed'       => 'The role could not be assigned.',
    'role.permissions_updated' => 'Role permissions updated.',

    // ── Permission ──
    'permission.not_found'        => 'Permission not found.',
    'permission.name_taken'       => 'That permission name is already in use.',
    'permission.deleted'          => 'Permission deleted.',
    'permission.invalid'          => 'One of the submitted permissions is invalid.',
    'permission.override_updated' => 'User permission updated.',
    'permission.override_failed'  => 'The user permission could not be updated.',

    // ── E-mail template ──
    'mail.password_reset.subject'  => 'Password reset',
    'mail.password_reset.greeting' => 'Hello,',
    'mail.password_reset.footer'   => 'If you did not request this, please ignore this e-mail.',

    // ── Validation ──
    'validation.failed' => 'Validation failed.',

    'validation.required'  => 'This field is required.',
    'validation.string'    => 'This field must be text.',
    'validation.integer'   => 'This field must be a whole number.',
    'validation.numeric'   => 'This field must be numeric.',
    'validation.boolean'   => 'This field must be true or false.',
    'validation.array'     => 'This field must be a list.',
    'validation.alpha'     => 'This field may contain letters only.',
    'validation.alpha_num' => 'This field may contain letters and numbers only.',
    'validation.email'     => 'Enter a valid e-mail address.',
    'validation.url'       => 'Enter a valid URL.',
    'validation.ip'        => 'Enter a valid IP address.',
    'validation.uuid'      => 'Enter a valid UUID.',
    'validation.date'      => 'Enter a valid date.',
    'validation.regex'     => 'The format of this field is invalid.',
    'validation.confirmed' => 'The confirmation does not match.',
    'validation.unique'    => 'This value is already in use.',

    // Parameterised — polymorphic rules (see the PLURALS note above).
    'validation.min'     => 'This field must be at least {min}.',
    'validation.max'     => 'This field must be at most {max}.',
    'validation.between' => 'This field must be between {min} and {max}.',
    'validation.in'      => 'This field must be one of: {values}',
    'validation.not_in'  => 'This value is not allowed.',

    // ── Parola sıfırlama (token akışı) ─────────────────────────
    'user.password_reset_sent'             => 'Your password reset request was received. If the address is registered, a reset link and code have been sent by e-mail.',
    'user.password_reset_failed'           => 'The password reset request could not be processed. Please try again later.',
    'user.reset_token_invalid'             => 'The reset link is invalid or has expired. Please request a new one.',
    'user.password_reset_done'             => 'Your password has been updated. You can now sign in with your new password.',
    'mail.password_reset.link_body'        => 'Click the link below to reset your password. The link can only be used once.',
    'mail.password_reset.link_button'      => 'Reset my password',
    'mail.password_reset.link_expiry'      => 'This link expires in {minutes, plural, one {# minute} other {# minutes}}.',
    'mail.password_reset.link_alt_body'    => 'Paste the link below into your browser to reset your password. It expires in {minutes, plural, one {# minute} other {# minutes}} and can only be used once.',
    'user.reset_code_invalid'              => 'The code is invalid or has expired. Please request a new password reset.',
    'mail.password_reset.code_body'        => 'Enter this code in the app to reset your password:',
    'mail.password_reset.code_alt_body'    => 'Your password reset code: {code}',
    'mail.password_reset.or_label'         => 'or',
];
