<?php

declare(strict_types=1);

/**
 * Türkçe mesaj kataloğu — VARSAYILAN locale.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * DOSYA ADI SÖZLEŞMESİ: `{domain}+intl-icu.{locale}.php`
 *
 * `+intl-icu` soneki Symfony'ye bu kataloğun ICU MessageFormat kullandığını
 * söyler. Sonuçları:
 *   • Yer tutucular `{min}` biçimindedir — `%min%` ya da `:min` DEĞİL.
 *   • Sayılar locale'e göre biçimlenir (ondalık ayıracı vb.).
 *   • Gerektiğinde `{n, plural, one {...} other {...}}` yazılabilir.
 *
 * ÇOĞUL NOTU: `min`/`max`/`between` kuralları POLİMORFİKTİR — aynı kural
 * string uzunluğuna, sayısal değere veya dizi eleman sayısına bakar
 * (bkz. System\Validation\Rules\Min::passes). Bu yüzden mesajlar "karakter"
 * gibi bir birim İÇEREMEZ ve çoğul çekim gerektirmez. ICU yine de tercih
 * edildi: birim-özel mesajlara ihtiyaç doğduğunda katalog formatını
 * değiştirmeden çoğul eklenebilir.
 * ─────────────────────────────────────────────────────────────────────────
 */

return [
    // ── Kimlik doğrulama ──
    'auth.required'              => 'Bu işlem için giriş yapmalısınız.',
    'auth.email_required'        => 'E-posta adresi zorunludur.',
    'auth.password_required'     => 'Parola zorunludur.',
    'auth.invalid_credentials'   => 'E-posta veya parola hatalı.',
    'auth.refresh_invalid'       => 'Oturum yenileme anahtarı geçersiz.',
    'auth.refresh_token_invalid' => 'Oturum yenileme anahtarı geçersiz veya kullanılmış.',
    'auth.logout_success'        => 'Oturum kapatıldı.',
    'auth.logout_failed'         => 'Oturum kapatılamadı.',

    // ── Kullanıcı ──
    'user.not_found'              => 'Kullanıcı bulunamadı.',
    'user.email_taken'            => 'Bu e-posta adresi zaten kayıtlı.',
    'user.username_taken'         => 'Bu kullanıcı adı zaten alınmış.',
    'user.register_failed'        => 'Kayıt tamamlanamadı.',
    'user.password_updated'       => 'Parolanız güncellendi.',
    'user.password_update_failed' => 'Parola güncellenemedi.',
    'user.current_password_wrong' => 'Mevcut parolanız hatalı.',
    'user.mail_failed'            => 'E-posta gönderilemedi.',
    'user.account_deleted'        => 'Hesabınız silindi.',
    'user.delete_failed'          => 'Hesap silinemedi.',
    'user.profile.no_changes'     => 'Güncellenecek bir alan gönderilmedi.',

    // ── Avatar ──
    'user.avatar.required'    => 'Bir görsel dosyası seçmelisiniz.',
    'user.avatar.not_found'   => 'Avatar bulunamadı.',
    'user.avatar.save_failed' => 'Avatar kaydedilemedi.',

    // ── Rol ──
    'role.not_found'           => 'Rol bulunamadı.',
    'role.name_taken'          => 'Bu rol adı zaten kullanılıyor.',
    'role.updated'             => 'Rol güncellendi.',
    'role.deleted'             => 'Rol silindi.',
    'role.in_use'              => 'Rol bir veya daha fazla kullanıcıya atanmış, silinemez.',
    'role.assigned'            => 'Rol atandı.',
    'role.assign_failed'       => 'Rol atanamadı.',
    'role.permissions_updated' => 'Rolün yetkileri güncellendi.',

    // ── Yetki ──
    'permission.not_found'        => 'Yetki bulunamadı.',
    'permission.name_taken'       => 'Bu yetki adı zaten kullanılıyor.',
    'permission.deleted'          => 'Yetki silindi.',
    'permission.invalid'          => 'Gönderilen yetkilerden biri geçersiz.',
    'permission.override_updated' => 'Kullanıcı yetkisi güncellendi.',
    'permission.override_failed'  => 'Kullanıcı yetkisi güncellenemedi.',

    // ── E-posta şablonu ──
    'mail.password_reset.subject'  => 'Parola sıfırlama',
    'mail.password_reset.greeting' => 'Merhaba,',
    'mail.password_reset.footer'   => 'Bu talebi siz yapmadıysanız bu e-postayı dikkate almayın.',

    // ── Doğrulama ──
    'validation.failed' => 'Doğrulama işlemi başarısız.',

    'validation.required'  => 'Bu alan zorunludur.',
    'validation.string'    => 'Bu alan metin olmalıdır.',
    'validation.integer'   => 'Bu alan tam sayı olmalıdır.',
    'validation.numeric'   => 'Bu alan sayısal olmalıdır.',
    'validation.boolean'   => 'Bu alan doğru/yanlış değeri olmalıdır.',
    'validation.array'     => 'Bu alan liste olmalıdır.',
    'validation.alpha'     => 'Bu alan yalnızca harf içerebilir.',
    'validation.alpha_num' => 'Bu alan yalnızca harf ve rakam içerebilir.',
    'validation.email'     => 'Geçerli bir e-posta adresi giriniz.',
    'validation.url'       => 'Geçerli bir adres (URL) giriniz.',
    'validation.ip'        => 'Geçerli bir IP adresi giriniz.',
    'validation.uuid'      => 'Geçerli bir UUID giriniz.',
    'validation.date'      => 'Geçerli bir tarih giriniz.',
    'validation.regex'     => 'Bu alanın biçimi geçersiz.',
    'validation.confirmed' => 'Alan doğrulaması eşleşmiyor.',
    'validation.unique'    => 'Bu değer zaten kullanılıyor.',

    // Parametreli — polimorfik kurallar (bkz. dosya başındaki ÇOĞUL NOTU).
    'validation.min'     => 'Bu alan en az {min} olmalıdır.',
    'validation.max'     => 'Bu alan en fazla {max} olmalıdır.',
    'validation.between' => 'Bu alan {min} ile {max} arasında olmalıdır.',
    'validation.in'      => 'Bu alan şu değerlerden biri olmalıdır: {values}',
    'validation.not_in'  => 'Bu değer kullanılamaz.',

    // ── Parola sıfırlama (token akışı) ─────────────────────────
    'user.password_reset_sent'             => 'Parola sıfırlama talebiniz alındı. Adres kayıtlıysa sıfırlama bağlantısı ve kodu e-posta ile gönderildi.',
    'user.password_reset_failed'           => 'Parola sıfırlama talebi işlenemedi. Lütfen daha sonra tekrar deneyin.',
    'user.reset_token_invalid'             => 'Sıfırlama bağlantısı geçersiz veya süresi dolmuş. Lütfen yeni bir talep oluşturun.',
    'user.password_reset_done'             => 'Parolanız güncellendi. Yeni parolanızla giriş yapabilirsiniz.',
    'mail.password_reset.link_body'        => 'Parolanızı sıfırlamak için aşağıdaki bağlantıya tıklayın. Bağlantı yalnızca bir kez kullanılabilir.',
    'mail.password_reset.link_button'      => 'Parolamı sıfırla',
    'mail.password_reset.link_expiry'      => 'Bu bağlantı {minutes, plural, one {# dakika} other {# dakika}} içinde geçerliliğini yitirir.',
    'mail.password_reset.link_alt_body'    => 'Parolanızı sıfırlamak için aşağıdaki bağlantıyı tarayıcınıza yapıştırın. Bağlantı {minutes, plural, one {# dakika} other {# dakika}} içinde geçerliliğini yitirir ve yalnızca bir kez kullanılabilir.',
    'user.reset_code_invalid'              => 'Kod geçersiz veya süresi dolmuş. Lütfen yeni bir sıfırlama talebi oluşturun.',
    'mail.password_reset.code_body'        => 'Parolanızı sıfırlamak için bu kodu uygulamaya girin:',
    'mail.password_reset.code_alt_body'    => 'Parola sıfırlama kodunuz: {code}',
    'mail.password_reset.or_label'         => 'veya',
];
