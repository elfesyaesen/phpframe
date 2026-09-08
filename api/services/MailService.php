<?php

namespace Api\Services;

use System\Config\MailConfig;
use PHPMailer\PHPMailer\PHPMailer;

class MailService
{
    public function __construct(
        private readonly Translator $Translator,
        private readonly LocaleResolver $LocaleResolver,
        private readonly MailConfig $mail,
    ) {}

    private function createMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $this->mail->host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $this->mail->username;
        $mail->Password   = $this->mail->password;
        $mail->SMTPSecure = $this->mail->protocol;
        $mail->Port       = $this->mail->port;
        // ── TLS DOĞRULAMASI — GÜVENLİK DÜZELTMESİ ────────────────────────
        //
        // Burada eskiden KOŞULSUZ olarak `verify_peer => false`,
        // `verify_peer_name => false`, `allow_self_signed => true` yazıyordu:
        // SMTP bağlantısı sertifika doğrulaması YAPMIYORDU. Araya girebilen
        // biri kendi sertifikasıyla oturup parola sıfırlama e-postalarını
        // (yani sıfırlama linklerini) ve `SMTP_PASSWORD`'ü okuyabilirdi.
        //
        // Artık `SMTP_VERIFY_TLS` kontrol ediyor ve VARSAYILANI `true`.
        // Doğrulama açıkken PHP'nin kendi güvenli varsayılanları kullanılır —
        // bu yüzden `SMTPOptions` HİÇ SET EDİLMEZ; boş bir dizi yazmak bile
        // gereksizdir. Yalnızca açıkça kapatıldığında override yazılır.
        if (!$this->mail->verifyTls) {
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ],
            ];
        }

        $mail->CharSet    = PHPMailer::CHARSET_UTF8;
        $mail->setFrom($this->mail->username, $this->mail->title);
        $mail->setLanguage($this->LocaleResolver->locale());

        return $mail;
    }

    /**
     * Parola sıfırlama LİNKİ ve OTP KODU gönderir — ikisi aynı mesajda.
     *
     * ─────────────────────────────────────────────────────────────────────
     * Neden link, neden parola değil: parola e-posta arşivinde DÜZ METİN ve
     * KALICI olarak durur. Posta hesabı ele geçirilirse uygulama hesabı da
     * gider — kullanıcı parolasını değiştirmiş olsa bile. Link ise tek
     * kullanımlıktır, süresi doler ve kullanıldığı an DB'den silinir.
     *
     * Ayrıca "mail gitti ama DB patladı" penceresi kapanır: link
     * kullanılmadıkça hesap DEĞİŞMEZ, dolayısıyla o durum zararsızdır.
     *
     * Neden kod da var: API mobil-only. Link'in çalışması için bir web
     * sayfası ya da universal/app link kurulumu gerekir; kod ise hiçbir şey
     * gerektirmez — kullanıcı mail'i bilgisayarda açsa ya da mail istemcisi
     * link'i tıklanabilir göstermese bile 6 haneyi uygulamaya yazabilir.
     * ─────────────────────────────────────────────────────────────────────
     */
    public function sendPasswordResetLink(string $to, string $url, string $code, int $ttlMinutes): bool
    {
        try {
            $t    = $this->Translator;
            $mail = $this->createMailer();
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $t->trans('mail.password_reset.subject');
            $mail->Body    = $this->resetLinkTemplate($url, $code, $ttlMinutes);

            // Düz metin alternatifi de HER İKİ yolu taşır: HTML'i
            // görüntülemeyen bir istemcide kod tek çıkış yolu olur.
            $mail->AltBody = $t->trans('mail.password_reset.code_alt_body', ['code' => $code])
                . "\n\n"
                . $t->trans('mail.password_reset.link_alt_body', ['minutes' => $ttlMinutes])
                . "\n\n" . $url;

            $mail->send();

            return true;
        } catch (\Throwable $e) {
            error_log('MailService hata: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Sıfırlama e-postası — kod ve link birlikte.
     *
     * `$url` HEM `href` HEM görünür metin olarak kullanılır ve İKİSİNDE DE
     * kaçışlanır: `htmlspecialchars` görünür metni, `ENT_QUOTES` ise
     * attribute bağlamını korur. URL uygulama tarafından üretildiği için
     * kullanıcı girdisi taşımaz, ama kaçış yine de bağlama göre yapılır —
     * "güvenilir kaynak" varsayımı ilk değişiklikte bozulur. Aynısı `$code`
     * için de geçerli.
     *
     * KOD ÖNCE, link sonra: mobil-only kurulumda kod her koşulda çalışır,
     * link ise yapılandırmaya bağlıdır. Çalışması garanti olanı üste almak,
     * `PASSWORD_RESET_URL` hatalı ayarlanmışsa kullanıcıyı çıkmaza
     * sokmuyor.
     */
    private function resetLinkTemplate(string $url, string $code, int $ttlMinutes): string
    {
        $t         = $this->Translator;
        $locale    = $this->LocaleResolver->locale();
        $safeUrl   = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $safeCode  = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        $subject   = htmlspecialchars($t->trans('mail.password_reset.subject'), ENT_QUOTES, 'UTF-8');
        $greeting  = htmlspecialchars($t->trans('mail.password_reset.greeting'), ENT_QUOTES, 'UTF-8');
        $codeBody  = htmlspecialchars($t->trans('mail.password_reset.code_body'), ENT_QUOTES, 'UTF-8');
        $orLabel   = htmlspecialchars($t->trans('mail.password_reset.or_label'), ENT_QUOTES, 'UTF-8');
        $body      = htmlspecialchars($t->trans('mail.password_reset.link_body'), ENT_QUOTES, 'UTF-8');
        $button    = htmlspecialchars($t->trans('mail.password_reset.link_button'), ENT_QUOTES, 'UTF-8');
        $expiry    = htmlspecialchars($t->trans('mail.password_reset.link_expiry', ['minutes' => $ttlMinutes]), ENT_QUOTES, 'UTF-8');
        $footer    = htmlspecialchars($t->trans('mail.password_reset.footer'), ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <!DOCTYPE html>
        <html lang="{$locale}">
        <head><meta charset="UTF-8"></head>
        <body style="margin:0;padding:0;background-color:#f0f2f5;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f0f2f5;padding:40px 0;">
                <tr><td align="center">
                    <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
                        <tr>
                            <td style="background:linear-gradient(135deg,#4f46e5,#7c3aed);padding:32px;text-align:center;">
                                <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:600;">{$subject}</h1>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:32px;color:#111827;font-size:15px;line-height:1.6;">
                                <p style="margin:0 0 16px;">{$greeting}</p>

                                <!-- OTP kodu — mobil uygulamaya elle girilir -->
                                <p style="margin:0 0 16px;">{$codeBody}</p>
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
                                    <tr>
                                        <td style="background-color:#f8f7ff;border:1px solid #e0e0ef;border-radius:8px;padding:20px;text-align:center;">
                                            <span style="font-size:30px;font-weight:700;letter-spacing:8px;color:#4f46e5;font-family:'Courier New',monospace;">{$safeCode}</span>
                                        </td>
                                    </tr>
                                </table>

                                <!-- Ayirici -->
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
                                    <tr>
                                        <td style="border-top:1px solid #e5e7eb;"></td>
                                        <td style="padding:0 12px;color:#9ca3af;font-size:12px;white-space:nowrap;">{$orLabel}</td>
                                        <td style="border-top:1px solid #e5e7eb;"></td>
                                    </tr>
                                </table>

                                <!-- Link yolu -->
                                <p style="margin:0 0 24px;">{$body}</p>
                                <p style="margin:0 0 24px;text-align:center;">
                                    <a href="{$safeUrl}" style="display:inline-block;padding:12px 28px;background-color:#4f46e5;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;">{$button}</a>
                                </p>
                                <p style="margin:0;color:#6b7280;font-size:12px;word-break:break-all;">{$safeUrl}</p>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:16px 32px 28px;color:#9ca3af;font-size:12px;text-align:center;border-top:1px solid #f3f4f6;">
                                {$expiry}<br>{$footer}
                            </td>
                        </tr>
                    </table>
                </td></tr>
            </table>
        </body>
        </html>
        HTML;
    }
}
