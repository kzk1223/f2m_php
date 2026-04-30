<?php
declare(strict_types=1);

/**
 * HTTPリクエスト値構築処理。
 */

namespace F2m\Request;

/**
 * POST値、添付ファイル、セッション値をアプリ内リクエスト配列へ集約。
 *
 * @param array<string, mixed> $postFields POST値。
 * @param array<string, mixed> $uploadedFiles アップロードファイル値。
 * @param array<string, mixed> $sessionValues セッション値。フォーム値、CSRFトークン、送信開始時刻の保持先。
 * @return array<string, mixed> アプリ内リクエスト配列。
 */
function build(array $postFields, array $uploadedFiles, array &$sessionValues): array
{
    // ---------------------------------------------
    // 制御値取得
    // ---------------------------------------------
    $mode = (string)($postFields['mode'] ?? 'confirm');
    $f2mId = (string)($postFields['F2M_ID'] ?? ($sessionValues['F2M_ID'] ?? ''));
    $returnRequested = has_return_request($postFields);

    // ---------------------------------------------
    // フォーム値の保持
    // ---------------------------------------------
    if ($mode === 'send' || $mode === 'form' || $returnRequested) {
        $formFields = $sessionValues['form'] ?? [];
    } else {
        $formFields = collect_form_fields($postFields, $uploadedFiles);
        $sessionValues['form'] = $formFields;
        $sessionValues['F2M_ID'] = $f2mId;
        $sessionValues['form_started_at'] = time();
    }

    // ---------------------------------------------
    // アプリ内リクエスト配列構築
    // ---------------------------------------------
    $request = [
        'mode' => $mode,
        'f2m_id' => $f2mId,
        'post_fields' => $postFields,
        'uploaded_files' => $uploadedFiles,
        'form_fields' => $formFields,
        'return_requested' => $returnRequested,
        'csrf_token' => (string)($postFields['csrf_token'] ?? ''),
    ];

    $request['session_values'] =& $sessionValues;

    return $request;
}

/**
 * セッションに保持したCSRFトークンとPOSTされたトークンを照合し、成功時に破棄。
 *
 * @param array<string, mixed> $request アプリ内リクエスト配列。
 * @return bool CSRFトークンが一致し、破棄できた場合はtrue。
 */
function consume_csrf_token(array $request): bool
{
    // ---------------------------------------------
    // CSRFトークン取得
    // ---------------------------------------------
    $postedToken = (string)($request['csrf_token'] ?? '');
    $sessionValues =& $request['session_values'];
    $sessionToken = (string)($sessionValues['csrf_token'] ?? '');

    if ($postedToken === '' || $sessionToken === '') {
        return false;
    }

    if (!hash_equals($sessionToken, $postedToken)) {
        return false;
    }

    // ---------------------------------------------
    // CSRFトークン破棄
    // ---------------------------------------------
    unset($sessionValues['csrf_token']);

    return true;
}

/**
 * POST値に確認画面からの戻りボタン項目が含まれるか判定。
 *
 * @param array<string, mixed> $postFields POST値。
 * @return bool 戻り要求がある場合はtrue。
 */
function has_return_request(array $postFields): bool
{
    // ---------------------------------------------
    // 戻りボタン項目検出
    // ---------------------------------------------
    foreach (array_keys($postFields) as $fieldName) {
        if (preg_match('/^F2M_RET/u', (string)$fieldName)) {
            return true;
        }
    }

    return false;
}

/**
 * 制御項目を除いたPOST値と添付ファイル名をフォーム値として収集。
 *
 * @param array<string, mixed> $postFields POST値。
 * @param array<string, mixed> $uploadedFiles アップロードファイル値。
 * @return array<string, string> フォーム項目名をキーにした入力値配列。
 */
function collect_form_fields(array $postFields, array $uploadedFiles): array
{
    $formFields = [];

    // ---------------------------------------------
    // POST値収集
    // ---------------------------------------------
    foreach ($postFields as $fieldName => $fieldValue) {
        if (is_control_field((string)$fieldName)) {
            continue;
        }

        $formFields[(string)$fieldName] = is_array($fieldValue)
            ? implode(',', array_map('strval', $fieldValue))
            : (string)$fieldValue;
    }

    // ---------------------------------------------
    // 添付ファイル名収集
    // ---------------------------------------------
    foreach ($uploadedFiles as $fieldName => $uploadedFile) {
        if (!is_array($uploadedFile)) {
            continue;
        }

        $formFields[(string)$fieldName] = (string)($uploadedFile['name'] ?? '');
    }

    return $formFields;
}

/**
 * mode、csrf_token、F2M_ID、戻りボタンなど、フォーム本文ではない制御項目を判定。
 *
 * @param string $fieldName フォーム項目名。
 * @return bool 制御項目の場合はtrue。
 */
function is_control_field(string $fieldName): bool
{
    // ---------------------------------------------
    // 制御項目判定
    // ---------------------------------------------
    return $fieldName === 'mode'
        || $fieldName === 'csrf_token'
        || $fieldName === 'F2M_ID'
        || preg_match('/^F2M_RET/u', $fieldName) === 1;
}
