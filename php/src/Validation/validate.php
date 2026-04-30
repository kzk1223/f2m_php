<?php
declare(strict_types=1);

/**
 * 入力値検証処理。
 */

namespace F2m\Validation;

/**
 * SPAM判定、必須、メール形式、一致、添付容量の各検証を行い、旧仕様のエラー配列を生成。
 *
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @param array<string, mixed> $request アプリ内リクエスト配列。
 * @param array<string, mixed> $uploadedFiles 保存済み添付ファイル情報。
 * @return array<int, array{fld: string, errmes: string}> エラー表示用配列。
 */
function validate(array $config, array $request, array $uploadedFiles = []): array
{
    $errors = [];
    $formFields = $request['form_fields'] ?? [];

    // ---------------------------------------------
    // SPAM判定
    // ---------------------------------------------
    $spamErrors = spam_errors($config, $request);

    if ($spamErrors !== []) {
        return $spamErrors;
    }

    // ---------------------------------------------
    // 必須・メール形式検証
    // ---------------------------------------------
    foreach (($config['F2M_JPNAME'] ?? []) as $fieldName => $fieldLabel) {
        if (isset($config['F2M_CHK'][$fieldName]) && is_blank_field($fieldName, $formFields, $request)) {
            $errors[] = [
                'fld' => $fieldLabel,
                'errmes' => file_required_error($fieldName, $request)
                    ? 'ファイル選択がされていないか、データ容量オーバーのため受信できません。'
                    : '入力されていません',
            ];
            continue;
        }

        if (isset($config['F2M_CHK_EMAIL'][$fieldName]) && ($formFields[$fieldName] ?? '') !== '') {
            if (!is_email((string)$formFields[$fieldName])) {
                $errors[] = [
                    'fld' => $fieldLabel,
                    'errmes' => '形式が不正です',
                ];
            }
        }
    }

    // ---------------------------------------------
    // 一致検証
    // ---------------------------------------------
    foreach (($config['F2M_CHK_EQ'] ?? []) as $fieldPair) {
        if (!preg_match('/^([^:]+):(.+)$/u', (string)$fieldPair, $matches)) {
            continue;
        }

        $firstField = $matches[1];
        $secondField = $matches[2];

        if (($formFields[$firstField] ?? '') !== ($formFields[$secondField] ?? '')) {
            $errors[] = [
                'fld' => field_label($firstField, $config) . ',' . field_label($secondField, $config),
                'errmes' => '一致しません',
            ];
        }
    }

    // ---------------------------------------------
    // 添付容量検証
    // ---------------------------------------------
    $maxFileSize = max_file_size($config['F2M_ATTACH_MAX'] ?? '3M');

    foreach (attach_fields($config) as $fieldName) {
        $uploadedFile = $request['uploaded_files'][$fieldName] ?? null;

        if (is_array($uploadedFile) && (int)($uploadedFile['size'] ?? 0) > $maxFileSize) {
            $errors[] = [
                'fld' => field_label($fieldName, $config),
                'errmes' => sprintf('容量オーバー(%sByteまで)', $config['F2M_ATTACH_MAX'] ?? '3M'),
            ];
        }
    }

    return $errors;
}

/**
 * honeypotと最短送信時間によるSPAM判定を実行。
 *
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @param array<string, mixed> $request アプリ内リクエスト配列。
 * @return array<int, array{fld: string, errmes: string}> SPAM判定エラー配列。
 */
function spam_errors(array $config, array $request): array
{
    // ---------------------------------------------
    // SPAM判定
    // ---------------------------------------------
    if (honeypot_filled($config, $request) || submitted_too_early($config, $request)) {
        return [
            [
                'fld' => '送信',
                'errmes' => '送信できませんでした',
            ],
        ];
    }

    return [];
}

/**
 * honeypot項目に値が入力されているか判定。
 *
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @param array<string, mixed> $request アプリ内リクエスト配列。
 * @return bool honeypot項目に値がある場合はtrue。
 */
function honeypot_filled(array $config, array $request): bool
{
    // ---------------------------------------------
    // honeypot項目判定
    // ---------------------------------------------
    $honeypotField = (string)($config['F2M_HONEYPOT_FLD'] ?? '');

    if ($honeypotField === '') {
        return false;
    }

    $formFields = $request['form_fields'] ?? [];

    return trim((string)($formFields[$honeypotField] ?? '')) !== '';
}

/**
 * フォーム受付から本送信までの経過秒数が設定値未満か判定。
 *
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @param array<string, mixed> $request アプリ内リクエスト配列。
 * @return bool 最短送信時間未満の場合はtrue。
 */
function submitted_too_early(array $config, array $request): bool
{
    // ---------------------------------------------
    // 最短送信時間判定
    // ---------------------------------------------
    $minimumSeconds = max(0, (int)($config['F2M_MIN_SUBMIT_SECONDS'] ?? 0));

    if ($minimumSeconds < 1 || ($request['mode'] ?? '') !== 'send') {
        return false;
    }

    $sessionValues = $request['session_values'] ?? [];
    $startedAt = (int)($sessionValues['form_started_at'] ?? 0);

    if ($startedAt < 1) {
        return true;
    }

    return (time() - $startedAt) < $minimumSeconds;
}

/**
 * 通常入力値または添付ファイル入力が必須条件を満たしているか判定。
 *
 * @param string $fieldName 検証対象の項目名。
 * @param array<string, mixed> $formFields フォーム入力値。
 * @param array<string, mixed> $request アプリ内リクエスト配列。
 * @return bool 空値または未添付の場合はtrue。
 */
function is_blank_field(string $fieldName, array $formFields, array $request): bool
{
    // ---------------------------------------------
    // 添付必須判定
    // ---------------------------------------------
    if (file_required_error($fieldName, $request)) {
        return true;
    }

    // ---------------------------------------------
    // 通常入力必須判定
    // ---------------------------------------------
    return !isset($formFields[$fieldName]) || $formFields[$fieldName] === '';
}

/**
 * 添付ファイル項目のアップロードサイズから未選択または容量超過状態を判定。
 *
 * @param string $fieldName 検証対象の項目名。
 * @param array<string, mixed> $request アプリ内リクエスト配列。
 * @return bool 添付ファイルのサイズが1バイト未満の場合はtrue。
 */
function file_required_error(string $fieldName, array $request): bool
{
    // ---------------------------------------------
    // アップロードサイズ判定
    // ---------------------------------------------
    $uploadedFile = $request['uploaded_files'][$fieldName] ?? null;

    return is_array($uploadedFile) && (int)($uploadedFile['size'] ?? 0) < 1;
}

/**
 * 旧版と同じ正規表現でメールアドレス形式を判定。
 *
 * @param string $email メールアドレス文字列。
 * @return bool 形式が一致する場合はtrue。
 */
function is_email(string $email): bool
{
    // ---------------------------------------------
    // 旧版互換メール形式判定
    // ---------------------------------------------
    return preg_match('/^([a-zA-Z0-9])+([a-zA-Z0-9._-])*@([a-zA-Z0-9_-])+([a-zA-Z0-9._-]+)+$/', $email) === 1;
}

/**
 * 設定内の日本語項目名を取得し、未定義時は項目名をそのまま返却。
 *
 * @param string $fieldName フォーム項目名。
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @return string 表示用項目名。
 */
function field_label(string $fieldName, array $config): string
{
    // ---------------------------------------------
    // 表示名フォールバック
    // ---------------------------------------------
    return (string)($config['F2M_JPNAME'][$fieldName] ?? $fieldName);
}

/**
 * F2M_ATTACH_FLDのカンマ区切り設定から添付対象項目名を取得。
 *
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @return array<int, string> 添付対象項目名配列。
 */
function attach_fields(array $config): array
{
    // ---------------------------------------------
    // 添付項目未設定判定
    // ---------------------------------------------
    if (empty($config['F2M_ATTACH_FLD'])) {
        return [];
    }

    // ---------------------------------------------
    // 添付項目配列化
    // ---------------------------------------------
    return array_values(array_filter(
        array_map('trim', explode(',', (string)$config['F2M_ATTACH_FLD'])),
        static fn (string $fieldName): bool => $fieldName !== ''
    ));
}

/**
 * F2M_ATTACH_MAXのK/M単位指定または数値指定をバイト数へ変換。
 *
 * @param string $maxSizeSetting 添付上限サイズ設定。
 * @return int 添付上限バイト数。
 */
function max_file_size(string $maxSizeSetting): int
{
    // ---------------------------------------------
    // 単位付きサイズ変換
    // ---------------------------------------------
    if (preg_match('/^(\d+)([MK])$/i', $maxSizeSetting, $matches)) {
        return (int)$matches[1] * (strtoupper($matches[2]) === 'K' ? 1024 : 1024 * 1024);
    }

    // ---------------------------------------------
    // 数値サイズ変換
    // ---------------------------------------------
    return (int)$maxSizeSetting;
}
