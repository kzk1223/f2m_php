<?php
declare(strict_types=1);

/**
 * メール送信処理。
 */

namespace F2m\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

/**
 * 管理者宛メールを生成し、設定された宛先へPHPMailerで送信。
 *
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @param array<string, mixed> $request アプリ内リクエスト配列。
 * @param array<string, mixed> $uploadedFiles 保存済み添付ファイル情報。
 * @return void
 * @throws RuntimeException PHPMailer利用または送信に失敗した場合。
 */
function send_admin(array $config, array $request, array $uploadedFiles = []): void
{
    $mailer = create_mailer($config);

    // ---------------------------------------------
    // 宛先設定
    // ---------------------------------------------
    foreach (mail_addresses((string)$config['F2M_TO']) as $mailAddress) {
        $mailer->addAddress($mailAddress);
    }

    // ---------------------------------------------
    // 件名・本文設定
    // ---------------------------------------------
    $mailer->Subject = (string)($config['F2M_SUBJECT'] ?? '');
    $mailer->Body = mail_body($config, $request, (string)($config['F2M_MAIL_TMPL'] ?? 'php/templates/mail.tpl'));

    add_attachments($mailer, $config, $request, $uploadedFiles);
    send_mailer($mailer);
}

/**
 * フォーム入力値から返信先を取得し、自動返信メールをPHPMailerで送信。
 *
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @param array<string, mixed> $request アプリ内リクエスト配列。
 * @param array<string, mixed> $uploadedFiles 保存済み添付ファイル情報。
 * @return void
 * @throws RuntimeException PHPMailer利用または送信に失敗した場合。
 */
function send_reply(array $config, array $request, array $uploadedFiles = []): void
{
    // ---------------------------------------------
    // 自動返信先取得
    // ---------------------------------------------
    $replyFieldName = (string)($config['F2M_RESV_TO_FLD'] ?? '');
    $formFields = $request['form_fields'] ?? [];
    $replyAddress = (string)($formFields[$replyFieldName] ?? '');

    if ($replyAddress === '') {
        return;
    }

    $mailer = create_mailer($config);
    $mailer->addAddress($replyAddress);

    // ---------------------------------------------
    // 件名・本文設定
    // ---------------------------------------------
    $mailer->Subject = (string)($config['F2M_RESV_SUBJECT'] ?? '');
    $mailer->Body = mail_body($config, $request, (string)($config['F2M_RESV_TMPL'] ?? 'php/templates/resv.tpl'));

    send_mailer($mailer);
}

/**
 * UTF-8メールとSMTP設定を反映したPHPMailerインスタンスを生成。
 *
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @return PHPMailer 設定済みPHPMailerインスタンス。
 * @throws RuntimeException PHPMailerクラスが存在しない場合。
 */
function create_mailer(array $config): PHPMailer
{
    // ---------------------------------------------
    // PHPMailer存在判定
    // ---------------------------------------------
    if (!class_exists(PHPMailer::class)) {
        throw new RuntimeException('PHPMailer not installed');
    }

    // ---------------------------------------------
    // 基本メール設定
    // ---------------------------------------------
    $mailer = new PHPMailer(true);
    $mailer->CharSet = 'UTF-8';
    $mailer->Encoding = 'base64';
    $mailer->setFrom((string)($config['F2M_FROM'] ?? ''), '');
    $mailer->Sender = (string)($config['F2M_FROM'] ?? '');

    // ---------------------------------------------
    // SMTP設定
    // ---------------------------------------------
    if (($config['F2M_MAIL_SENDER'] ?? '') === 'smtp') {
        $mailer->isSMTP();
        $mailer->Host = (string)($config['F2M_SMTP_HOST'] ?? 'localhost');
        $mailer->Port = (int)($config['F2M_SMTP_PORT'] ?? 25);

        if (smtp_auth_enabled($config)) {
            $mailer->SMTPAuth = true;
            $mailer->Username = (string)$config['F2M_SMTP_USER'];
            $mailer->Password = (string)$config['F2M_SMTP_PASSWORD'];
        }
    }

    return $mailer;
}

/**
 * フォーム値から_allを含むSmarty割当値を作成し、UTF-8本文を生成。
 *
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @param array<string, mixed> $request アプリ内リクエスト配列。
 * @param string $templatePath メール本文テンプレートパス。
 * @return string UTF-8メール本文。
 * @throws RuntimeException テンプレートが存在しない場合。
 */
function mail_body(array $config, array $request, string $templatePath): string
{
    $formFields = $request['form_fields'] ?? [];
    $bodyLines = [];
    $templateValues = [];

    // ---------------------------------------------
    // メール本文用フォーム値構築
    // ---------------------------------------------
    foreach (($config['F2M_JPNAME'] ?? []) as $fieldName => $fieldLabel) {
        $fieldValue = (string)($formFields[$fieldName] ?? '');
        $templateValues[$fieldName] = $fieldValue;
        $bodyLines[] = sprintf('%s = %s', $fieldLabel, $fieldValue);
    }

    $templateValues['_all'] = implode("\n", $bodyLines);

    // ---------------------------------------------
    // テンプレート存在判定
    // ---------------------------------------------
    if (!is_file(resolve_path($config, $templatePath))) {
        throw new RuntimeException(sprintf('%s not found', $templatePath));
    }

    // ---------------------------------------------
    // Smartyテンプレート展開
    // ---------------------------------------------
    $mailBody = \F2m\Render\fetch_template($config, $templatePath, [
        'form' => $templateValues,
    ]);

    // ---------------------------------------------
    // メール本文返却
    // ---------------------------------------------
    return $mailBody;
}

/**
 * セッションに保持された一時保存済み添付ファイルを管理者宛メールへ追加。
 *
 * @param PHPMailer $mailer PHPMailerインスタンス。
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @param array<string, mixed> $request アプリ内リクエスト配列。
 * @param array<string, mixed> $uploadedFiles 保存済み添付ファイル情報。
 * @return void
 */
function add_attachments(PHPMailer $mailer, array $config, array $request, array $uploadedFiles = []): void
{
    // ---------------------------------------------
    // 添付設定判定
    // ---------------------------------------------
    if (empty($config['F2M_ATTACH_FLD'])) {
        return;
    }

    $sessionValues = $request['session_values'] ?? [];
    $attachedFiles = $uploadedFiles !== []
        ? $uploadedFiles
        : ($sessionValues['attach_file'] ?? []);

    // ---------------------------------------------
    // 添付ファイル追加
    // ---------------------------------------------
    foreach (mail_attach_fields($config) as $fieldName) {
        $attachedFile = $attachedFiles[$fieldName] ?? null;

        if (!is_array($attachedFile)) {
            continue;
        }

        $storedPath = (string)($attachedFile['path'] ?? '');

        if ($storedPath === '' && isset($attachedFile['fname'])) {
            $storedPath = ($config['_root_dir'] ?? dirname(__DIR__, 3)) . '/php/var/uploads/' . $attachedFile['fname'];
        }

        if ($storedPath !== '' && is_file($storedPath)) {
            $mailer->addAttachment($storedPath, (string)$attachedFile['name']);
        }
    }
}

/**
 * PHPMailerの送信処理を実行し、失敗時は旧仕様に近い送信失敗例外を発生。
 *
 * @param PHPMailer $mailer PHPMailerインスタンス。
 * @return void
 * @throws RuntimeException メール送信に失敗した場合。
 */
function send_mailer(PHPMailer $mailer): void
{
    // ---------------------------------------------
    // メール送信実行
    // ---------------------------------------------
    if (!$mailer->send()) {
        throw new RuntimeException('send mail failed. ' . $mailer->ErrorInfo);
    }
}

/**
 * カンマ区切りのメールアドレス設定を空要素を除いた配列へ変換。
 *
 * @param string $mailAddresses カンマ区切りメールアドレス。
 * @return array<int, string> メールアドレス配列。
 */
function mail_addresses(string $mailAddresses): array
{
    // ---------------------------------------------
    // 宛先配列化
    // ---------------------------------------------
    return array_values(array_filter(
        array_map('trim', explode(',', $mailAddresses)),
        static fn (string $mailAddress): bool => $mailAddress !== ''
    ));
}

/**
 * F2M_SMTP_AUTHのtrue/yes/1指定からSMTP認証利用有無を判定。
 *
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @return bool SMTP認証を利用する場合はtrue。
 */
function smtp_auth_enabled(array $config): bool
{
    // ---------------------------------------------
    // SMTP認証フラグ判定
    // ---------------------------------------------
    return isset($config['F2M_SMTP_AUTH'])
        && preg_match('/^(true|yes|1)$/i', (string)$config['F2M_SMTP_AUTH']) === 1;
}

/**
 * F2M_ATTACH_FLDのカンマ区切り設定からメール添付対象項目名を取得。
 *
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @return array<int, string> 添付対象項目名配列。
 */
function mail_attach_fields(array $config): array
{
    // ---------------------------------------------
    // 添付項目配列化
    // ---------------------------------------------
    return array_values(array_filter(
        array_map('trim', explode(',', (string)($config['F2M_ATTACH_FLD'] ?? ''))),
        static fn (string $fieldName): bool => $fieldName !== ''
    ));
}

/**
 * 絶対パスはそのまま返し、相対パスはrootディレクトリ基準で解決。
 *
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @param string $path 解決対象のファイルパス。
 * @return string 解決済みファイルパス。
 */
function resolve_path(array $config, string $path): string
{
    // ---------------------------------------------
    // 絶対パス判定
    // ---------------------------------------------
    if (preg_match('/^(?:[A-Za-z]:)?[\/\\\\]/', $path) === 1) {
        return $path;
    }

    // ---------------------------------------------
    // root相対パス解決
    // ---------------------------------------------
    return (string)($config['_root_dir'] ?? dirname(__DIR__, 3)) . DIRECTORY_SEPARATOR . $path;
}
