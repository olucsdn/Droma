<?php
declare(strict_types=1);

/**
 * Serviço Nativo de Envio de E-mails Transacionais (SMTP com SSL/TLS) — Droma Financy
 * Zero dependências externas, compatível com Gmail SMTP e provedores profissionais.
 */
final class Mailer {

    private static function getSetting(string $key, string $default = ''): string {
        $val = getenv($key);
        if ($val !== false && $val !== '') {
            return trim($val);
        }
        if (!empty($_ENV[$key])) {
            return trim((string)$_ENV[$key]);
        }
        return $default;
    }

    /**
     * Envia um e-mail transacional via socket SMTP seguro (SSL porta 465)
     */
    public static function send(string $toEmail, string $subject, string $htmlBody, string $textBody = ''): bool {
        $host     = self::getSetting('SMTP_HOST', 'smtp.gmail.com');
        $port     = (int) self::getSetting('SMTP_PORT', '465');
        $user     = self::getSetting('SMTP_USER', 'dromafinancy@gmail.com');
        $pass     = self::getSetting('SMTP_PASS', '***REMOVIDO***');
        $fromName = self::getSetting('SMTP_FROM_NAME', 'Droma Financy');

        // Remove espaços de senhas de app do Google
        $pass = str_replace(' ', '', $pass);

        $socket = @fsockopen("ssl://{$host}", $port, $errno, $errstr, 15);
        if (!$socket) {
            error_log("[Mailer Error] Falha de conexão SMTP: {$errno} - {$errstr}");
            return false;
        }

        try {
            self::readResponse($socket); // 220 banner

            self::sendCommand($socket, "EHLO droma.local", 250);
            self::sendCommand($socket, "AUTH LOGIN", 334);
            self::sendCommand($socket, base64_encode($user), 334);
            self::sendCommand($socket, base64_encode($pass), 235);

            self::sendCommand($socket, "MAIL FROM: <{$user}>", 250);
            self::sendCommand($socket, "RCPT TO: <{$toEmail}>", 250);
            self::sendCommand($socket, "DATA", 354);

            $boundary = "droma_boundary_" . md5(uniqid((string)mt_rand(), true));
            $encodedSubject = "=?UTF-8?B?" . base64_encode($subject) . "?=";
            $encodedFromName = "=?UTF-8?B?" . base64_encode($fromName) . "?=";

            $headers = [
                "From: {$encodedFromName} <{$user}>",
                "To: <{$toEmail}>",
                "Subject: {$encodedSubject}",
                "Date: " . date('r'),
                "MIME-Version: 1.0",
                "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
                "X-Mailer: Droma Mailer v1.0",
                ""
            ];

            if ($textBody === '') {
                $textBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody));
            }

            $message = [
                "--{$boundary}",
                "Content-Type: text/plain; charset=UTF-8",
                "Content-Transfer-Encoding: base64",
                "",
                chunk_split(base64_encode($textBody)),
                "--{$boundary}",
                "Content-Type: text/html; charset=UTF-8",
                "Content-Transfer-Encoding: base64",
                "",
                chunk_split(base64_encode($htmlBody)),
                "--{$boundary}--",
                "."
            ];

            $payload = implode("\r\n", array_merge($headers, $message));
            self::sendCommand($socket, $payload, 250);

            self::sendCommand($socket, "QUIT", 221);
            fclose($socket);
            return true;
        } catch (Throwable $e) {
            error_log("[Mailer Error] Exceção no envio: " . $e->getMessage());
            @fclose($socket);
            return false;
        }
    }

    private static function readResponse($socket): string {
        $res = '';
        while ($line = fgets($socket, 512)) {
            $res .= $line;
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }
        return $res;
    }

    private static function sendCommand($socket, string $cmd, int $expectedCode): string {
        fputs($socket, $cmd . "\r\n");
        $res = self::readResponse($socket);
        $code = (int) substr($res, 0, 3);
        if ($code !== $expectedCode) {
            throw new RuntimeException("Comando SMTP rejeitado: '{$cmd}' retornou '{$res}', esperado '{$expectedCode}'");
        }
        return $res;
    }

    /**
     * Envia e-mail de recuperação de senha com link assinado e token único
     */
    public static function sendPasswordReset(string $toEmail, string $userName, string $token): bool {
        $baseUrl = self::getSetting('APP_URL', 'https://droma-133b.onrender.com');
        $resetUrl = rtrim($baseUrl, '/') . "/reset_password?token=" . urlencode($token);

        $subject = "Recuperação de Senha — Droma Financy";

        $html = self::renderTemplate(
            title: "Recuperação de Senha",
            userName: $userName,
            bodyParagraphs: [
                "Recebemos uma solicitação para redefinir a senha da sua conta no <strong>Droma</strong>.",
                "Clique no botão seguro abaixo para cadastrar uma nova senha de acesso. Por segurança, este link é válido por <strong>1 hora</strong>:"
            ],
            buttonText: "Redefinir Minha Senha",
            buttonUrl: $resetUrl,
            footerNote: "Se você não solicitou a alteração da sua senha, fique tranquilo: nenhuma alteração foi feita e você pode ignorar este e-mail com segurança."
        );

        return self::send($toEmail, $subject, $html);
    }

    /**
     * Envia e-mail com código numérico de 6 dígitos para verificação de conta
     */
    public static function sendEmailVerification(string $toEmail, string $userName, string $code): bool {
        $baseUrl = self::getSetting('APP_URL', 'https://droma-133b.onrender.com');
        $verifyUrl = rtrim($baseUrl, '/') . "/verify_email?email=" . urlencode($toEmail) . "&code=" . urlencode($code);

        $subject = "Código de Confirmação: {$code} — Droma Financy";

        $html = self::renderVerificationCodeTemplate(
            userName: $userName,
            code: $code,
            verifyUrl: $verifyUrl
        );

        return self::send($toEmail, $subject, $html);
    }

    /**
     * Template de e-mail exclusivo para verificação com código numérico de 6 dígitos
     */
    private static function renderVerificationCodeTemplate(string $userName, string $code, string $verifyUrl): string {
        $formattedCode = implode(' ', str_split($code));

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <title>Código de Confirmação Droma</title>
</head>
<body style="margin: 0; padding: 30px 15px; background-color: #070A0E; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #F8FAFC;">
  <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 580px; margin: 0 auto; background-color: #121826; border: 1px solid #1F2A3F; border-radius: 14px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
    <!-- Cabeçalho com Brand -->
    <tr>
      <td style="padding: 28px 32px; background: linear-gradient(135deg, #1A2234 0%, #121826 100%); border-bottom: 1px solid #1F2A3F;">
        <table role="presentation" border="0" cellpadding="0" cellspacing="0">
          <tr>
            <td style="width: 36px; height: 36px; background: linear-gradient(135deg, #D4AF6C 0%, #A17632 100%); border-radius: 8px; text-align: center; color: #0A0E17; font-weight: 800; font-size: 20px; line-height: 36px;">
              D
            </td>
            <td style="padding-left: 12px; font-size: 20px; font-weight: 700; color: #F8FAFC;">
              Droma<span style="color: #D4AF6C;">.</span>
            </td>
          </tr>
        </table>
      </td>
    </tr>
    <!-- Conteúdo -->
    <tr>
      <td style="padding: 32px 32px 24px 32px;">
        <h1 style="margin: 0 0 18px 0; font-size: 22px; color: #F8FAFC; font-weight: 700;">Confirmação de E-mail</h1>
        <p style="margin: 0 0 16px 0; color: #94A3B8; font-size: 15px;">Olá, <strong style="color: #F8FAFC;">{$userName}</strong>,</p>
        <p style="margin: 0 0 20px 0; color: #CBD5E1; font-size: 15px; line-height: 1.6;">
          Seu código de confirmação para ativar sua conta no <strong>Droma Financy</strong> é:
        </p>

        <!-- Caixa de Destaque do Código de 6 Dígitos -->
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin: 24px 0;">
          <tr>
            <td align="center">
              <div style="display: inline-block; background: #0A0E17; border: 2px dashed #D4AF6C; border-radius: 12px; padding: 16px 32px; text-align: center;">
                <span style="font-size: 34px; font-weight: 800; letter-spacing: 10px; color: #F7E5B8; font-family: ui-monospace, Menlo, Monaco, Consolas, monospace;">
                  {$formattedCode}
                </span>
              </div>
            </td>
          </tr>
        </table>

        <p style="margin: 0 0 18px 0; color: #94A3B8; font-size: 14px; text-align: center; line-height: 1.5;">
          Digite este código de 6 dígitos no sistema para ativar seu acesso com segurança.
        </p>

        <!-- Botão Opcional de Acesso Direto -->
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="margin: 20px auto 10px auto;">
          <tr>
            <td align="center" style="border-radius: 8px; background: linear-gradient(135deg, #D4AF6C 0%, #B8863D 100%);">
              <a href="{$verifyUrl}" target="_blank" style="font-size: 14px; font-weight: 700; color: #0A0E17; text-decoration: none; padding: 12px 24px; border-radius: 8px; display: inline-block;">
                Acessar Tela de Verificação
              </a>
            </td>
          </tr>
        </table>
      </td>
    </tr>
    <!-- Rodapé -->
    <tr>
      <td style="padding: 20px 32px; background-color: #0A0E17; border-top: 1px solid #1F2A3F; color: #64748B; font-size: 12px; line-height: 1.5;">
        Se você não solicitou este código, fique tranquilo: basta ignorar esta mensagem.<br><br>
        &copy; 2026 Droma Financy &bull; Gestão Financeira Pessoal com Clareza e Controle.
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    /**
     * Template de e-mail moderno no padrão visual escuro/dourado do Droma
     */
    private static function renderTemplate(string $title, string $userName, array $bodyParagraphs, string $buttonText, string $buttonUrl, string $footerNote): string {
        $paragraphsHtml = '';
        foreach ($bodyParagraphs as $p) {
            $paragraphsHtml .= "<p style=\"margin: 0 0 16px 0; color: #CBD5E1; font-size: 15px; line-height: 1.6;\">{$p}</p>";
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <title>{$title}</title>
</head>
<body style="margin: 0; padding: 30px 15px; background-color: #070A0E; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #F8FAFC;">
  <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 580px; margin: 0 auto; background-color: #121826; border: 1px solid #1F2A3F; border-radius: 14px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
    <!-- Cabeçalho com Brand -->
    <tr>
      <td style="padding: 28px 32px; background: linear-gradient(135deg, #1A2234 0%, #121826 100%); border-bottom: 1px solid #1F2A3F;">
        <table role="presentation" border="0" cellpadding="0" cellspacing="0">
          <tr>
            <td style="width: 36px; height: 36px; background: linear-gradient(135deg, #D4AF6C 0%, #A17632 100%); border-radius: 8px; text-align: center; color: #0A0E17; font-weight: 800; font-size: 20px; line-height: 36px;">
              D
            </td>
            <td style="padding-left: 12px; font-size: 20px; font-weight: 700; color: #F8FAFC;">
              Droma<span style="color: #D4AF6C;">.</span>
            </td>
          </tr>
        </table>
      </td>
    </tr>
    <!-- Conteúdo -->
    <tr>
      <td style="padding: 32px 32px 24px 32px;">
        <h1 style="margin: 0 0 18px 0; font-size: 22px; color: #F8FAFC; font-weight: 700;">{$title}</h1>
        <p style="margin: 0 0 16px 0; color: #94A3B8; font-size: 15px;">Olá, <strong style="color: #F8FAFC;">{$userName}</strong>,</p>
        {$paragraphsHtml}
        
        <!-- Botão CTA -->
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="margin: 28px 0;">
          <tr>
            <td align="center" style="border-radius: 8px; background: linear-gradient(135deg, #D4AF6C 0%, #B8863D 100%);">
              <a href="{$buttonUrl}" target="_blank" style="font-size: 15px; font-weight: 700; color: #0A0E17; text-decoration: none; padding: 13px 28px; border-radius: 8px; display: inline-block;">
                {$buttonText}
              </a>
            </td>
          </tr>
        </table>

        <p style="margin: 0; color: #64748B; font-size: 13px; line-height: 1.5;">
          Se o botão não funcionar, copie e cole este link no seu navegador:<br>
          <a href="{$buttonUrl}" style="color: #3B82F6; word-break: break-all; text-decoration: underline;">{$buttonUrl}</a>
        </p>
      </td>
    </tr>
    <!-- Rodapé -->
    <tr>
      <td style="padding: 20px 32px; background-color: #0A0E17; border-top: 1px solid #1F2A3F; color: #64748B; font-size: 12px; line-height: 1.5;">
        {$footerNote}<br><br>
        &copy; 2026 Droma Financy &bull; Gestão Financeira Pessoal com Clareza e Controle.
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }
}
