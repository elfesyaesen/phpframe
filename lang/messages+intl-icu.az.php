<?php

declare(strict_types=1);

/**
 * Azərbaycan dili mesaj kataloqu.
 *
 * Adlandırma və ICU qeydləri: `messages+intl-icu.tr.php` faylına baxın.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * ⚠ GÖZDƏN KEÇİRİLMƏLİDİR / GÖZDEN GEÇİRİLMELİ
 *
 * Bu katalog Türkçe kataloğun karşılığı olarak hazırlanmıştır. Azerbaycan
 * Türkçesi Türkiye Türkçesine yakın olsa da sözcük seçimi ve ekler farklıdır
 * (örn. "parol" / "şifrə", "istifadəçi" / "kullanıcı"). Yayına almadan önce
 * ana dili Azerbaycanca olan biri tarafından gözden geçirilmelidir.
 *
 * Alternatif: `LocaleResolver::SUPPORTED` içinden `az` çıkarılırsa istekler
 * varsayılan locale'e (tr) düşer ve bu dosyaya gerek kalmaz.
 * ─────────────────────────────────────────────────────────────────────────
 */

return [
    // ── Kimlik doğrulama ──
    'auth.required'              => 'Bu əməliyyat üçün daxil olmalısınız.',
    'auth.email_required'        => 'E-poçt ünvanı mütləqdir.',
    'auth.password_required'     => 'Parol mütləqdir.',
    'auth.invalid_credentials'   => 'E-poçt və ya parol səhvdir.',
    'auth.refresh_invalid'       => 'Sessiya yeniləmə açarı etibarsızdır.',
    'auth.refresh_token_invalid' => 'Sessiya yeniləmə açarı etibarsızdır və ya artıq istifadə olunub.',
    'auth.logout_success'        => 'Sessiya bağlandı.',
    'auth.logout_failed'         => 'Sessiya bağlanmadı.',

    // ── İstifadəçi ──
    'user.not_found'              => 'İstifadəçi tapılmadı.',
    'user.email_taken'            => 'Bu e-poçt ünvanı artıq qeydiyyatdadır.',
    'user.username_taken'         => 'Bu istifadəçi adı artıq götürülüb.',
    'user.register_failed'        => 'Qeydiyyat tamamlanmadı.',
    'user.password_updated'       => 'Parolunuz yeniləndi.',
    'user.password_update_failed' => 'Parol yenilənmədi.',
    'user.current_password_wrong' => 'Cari parolunuz səhvdir.',
    'user.mail_failed'            => 'E-poçt göndərilmədi.',
    'user.account_deleted'        => 'Hesabınız silindi.',
    'user.delete_failed'          => 'Hesab silinmədi.',
    'user.profile.no_changes'     => 'Yenilənəcək sahə göndərilmədi.',

    // ── Avatar ──
    'user.avatar.required'    => 'Şəkil faylı seçməlisiniz.',
    'user.avatar.not_found'   => 'Avatar tapılmadı.',
    'user.avatar.save_failed' => 'Avatar saxlanılmadı.',

    // ── Rol ──
    'role.not_found'           => 'Rol tapılmadı.',
    'role.name_taken'          => 'Bu rol adı artıq istifadə olunur.',
    'role.updated'             => 'Rol yeniləndi.',
    'role.deleted'             => 'Rol silindi.',
    'role.in_use'              => 'Rol bir və ya bir neçə istifadəçiyə təyin edilib, silinə bilməz.',
    'role.assigned'            => 'Rol təyin edildi.',
    'role.assign_failed'       => 'Rol təyin edilmədi.',
    'role.permissions_updated' => 'Rolun səlahiyyətləri yeniləndi.',

    // ── Səlahiyyət ──
    'permission.not_found'        => 'Səlahiyyət tapılmadı.',
    'permission.name_taken'       => 'Bu səlahiyyət adı artıq istifadə olunur.',
    'permission.deleted'          => 'Səlahiyyət silindi.',
    'permission.invalid'          => 'Göndərilən səlahiyyətlərdən biri etibarsızdır.',
    'permission.override_updated' => 'İstifadəçi səlahiyyəti yeniləndi.',
    'permission.override_failed'  => 'İstifadəçi səlahiyyəti yenilənmədi.',

    // ── E-poçt şablonu ──
    'mail.password_reset.subject'  => 'Parol sıfırlama',
    'mail.password_reset.greeting' => 'Salam,',
    'mail.password_reset.footer'   => 'Bu sorğunu siz etməmisinizsə, bu e-poçtu nəzərə almayın.',

    // ── Doğrulama ──
    'validation.failed' => 'Doğrulama uğursuz oldu.',

    'validation.required'  => 'Bu sahə mütləqdir.',
    'validation.string'    => 'Bu sahə mətn olmalıdır.',
    'validation.integer'   => 'Bu sahə tam ədəd olmalıdır.',
    'validation.numeric'   => 'Bu sahə rəqəm olmalıdır.',
    'validation.boolean'   => 'Bu sahə doğru/yanlış dəyəri olmalıdır.',
    'validation.array'     => 'Bu sahə siyahı olmalıdır.',
    'validation.alpha'     => 'Bu sahə yalnız hərf ola bilər.',
    'validation.alpha_num' => 'Bu sahə yalnız hərf və rəqəm ola bilər.',
    'validation.email'     => 'Etibarlı e-poçt ünvanı daxil edin.',
    'validation.url'       => 'Etibarlı ünvan (URL) daxil edin.',
    'validation.ip'        => 'Etibarlı IP ünvanı daxil edin.',
    'validation.uuid'      => 'Etibarlı UUID daxil edin.',
    'validation.date'      => 'Etibarlı tarix daxil edin.',
    'validation.regex'     => 'Bu sahənin formatı etibarsızdır.',
    'validation.confirmed' => 'Təsdiq uyğun gəlmir.',
    'validation.unique'    => 'Bu dəyər artıq istifadə olunur.',

    // Parametrli — polimorfik qaydalar.
    'validation.min'     => 'Bu sahə ən azı {min} olmalıdır.',
    'validation.max'     => 'Bu sahə ən çoxu {max} olmalıdır.',
    'validation.between' => 'Bu sahə {min} ilə {max} arasında olmalıdır.',
    'validation.in'      => 'Bu sahə bu dəyərlərdən biri olmalıdır: {values}',
    'validation.not_in'  => 'Bu dəyər istifadə oluna bilməz.',

    // ── Parola sıfırlama (token akışı) ─────────────────────────
    'user.password_reset_sent'             => 'Parol sıfırlama tələbiniz qəbul edildi. Ünvan qeydiyyatdan keçibsə, sıfırlama linki və kodu e-poçtla göndərildi.',
    'user.password_reset_failed'           => 'Parol sıfırlama tələbi emal edilə bilmədi. Zəhmət olmasa daha sonra yenidən cəhd edin.',
    'user.reset_token_invalid'             => 'Sıfırlama linki etibarsızdır və ya vaxtı bitmişdir. Zəhmət olmasa yeni tələb yaradın.',
    'user.password_reset_done'             => 'Parolunuz yeniləndi. Yeni parolunuzla daxil ola bilərsiniz.',
    'mail.password_reset.link_body'        => 'Parolunuzu sıfırlamaq üçün aşağıdaki linkə klikləyin. Link yalnız bir dəfə istifadə edilə bilər.',
    'mail.password_reset.link_button'      => 'Parolumu sıfırla',
    'mail.password_reset.link_expiry'      => 'Bu link {minutes, plural, one {# dəqiqə} other {# dəqiqə}} sonra etibarsız olur.',
    'mail.password_reset.link_alt_body'    => 'Parolunuzu sıfırlamaq üçün aşağıdaki linki brauzerinizə köçürün. Link {minutes, plural, one {# dəqiqə} other {# dəqiqə}} sonra etibarsız olur və yalnız bir dəfə istifadə edilə bilər.',
    'user.reset_code_invalid'              => 'Kod etibarsızdır və ya vaxtı bitmişdir. Zəhmət olmasa yeni sıfırlama tələbi yaradın.',
    'mail.password_reset.code_body'        => 'Parolunuzu sıfırlamaq üçün bu kodu tətbiqə daxil edin:',
    'mail.password_reset.code_alt_body'    => 'Parol sıfırlama kodunuz: {code}',
    'mail.password_reset.or_label'         => 'və ya',
];
