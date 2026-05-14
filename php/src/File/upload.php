<?php
declare(strict_types=1);

/**
 * 添付ファイル処理。
 */

namespace F2m\File;

use RuntimeException;

/**
 * 設定された添付項目のアップロードファイルを一時保存し、attach tokenへ保存情報を格納。
 *
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @param array<string, mixed> $request アプリ内リクエスト配列。
 * @return array<string, array<string, mixed>> 保存済み添付ファイル情報。
 * @throws RuntimeException 保存先作成またはアップロード保存に失敗した場合。
 */
function upload(array $config, array &$request): array
{
    // ---------------------------------------------
    // 添付項目取得
    // ---------------------------------------------
    $attachFields = attach_fields($config);

    if ($attachFields === []) {
        return [];
    }

    $uploadDirectory = upload_directory($config);
    $sessionValues =& $request['session_values'];

    // ---------------------------------------------
    // 保存先ディレクトリ作成
    // ---------------------------------------------
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0775, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException(sprintf('%s create failed', $uploadDirectory));
    }

    // ---------------------------------------------
    // 期限切れトークン整理
    // ---------------------------------------------
    refresh_attach_tokens($config, $sessionValues);

    // ---------------------------------------------
    // 保存済み添付ファイル復元
    // ---------------------------------------------
    $attachToken = normalize_attach_token((string)($request['attach_token'] ?? ''));
    $savedFiles = $attachToken !== ''
        ? stored_files($config, $request, $attachToken)
        : [];

    if ($savedFiles === []) {
        $attachToken = '';
    }

    // ---------------------------------------------
    // 新規添付ファイル保存
    // ---------------------------------------------
    $hasNewUpload = false;

    foreach ($attachFields as $fieldName) {
        $uploadedFile = $request['uploaded_files'][$fieldName] ?? null;

        if (!is_array($uploadedFile)) {
            continue;
        }

        $uploadError = (int)($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($uploadError !== UPLOAD_ERR_NO_FILE && $uploadError !== UPLOAD_ERR_OK) {
            $hasNewUpload = true;
            delete_attached_file($config, $savedFiles[$fieldName] ?? []);
            unset($savedFiles[$fieldName]);

            if ((int)($uploadedFile['size'] ?? 0) > 0) {
                throw new RuntimeException('file upload errorr !!');
            }

            continue;
        }

        if (!file_selected($uploadedFile)) {
            continue;
        }

        $hasNewUpload = true;

        if ($attachToken === '') {
            $attachToken = create_attach_token($sessionValues);
        }

        $storedName = temporary_filename($uploadDirectory);
        $storedPath = $uploadDirectory . DIRECTORY_SEPARATOR . $storedName;

        if (!move_uploaded_file((string)$uploadedFile['tmp_name'], $storedPath)) {
            throw new RuntimeException('file upload errorr !!');
        }

        delete_attached_file($config, $savedFiles[$fieldName] ?? []);

        $savedFile = $uploadedFile;
        $savedFile['fname'] = $storedName;
        $savedFile['path'] = $storedPath;

        $savedFiles[$fieldName] = $savedFile;
    }

    if ($attachToken === '' || $savedFiles === []) {
        if ($attachToken !== '' && isset($sessionValues['attach_files'][$attachToken])) {
            unset($sessionValues['attach_files'][$attachToken]);
        }

        unset($sessionValues['attach_file']);
        $request['attach_token'] = '';
        return [];
    }

    // ---------------------------------------------
    // セッション保存
    // ---------------------------------------------
    if (!isset($sessionValues['attach_files']) || !is_array($sessionValues['attach_files'])) {
        $sessionValues['attach_files'] = [];
    }

    $sessionValues['attach_files'][$attachToken] = [
        'f2m_id' => (string)($request['f2m_id'] ?? ($config['_id'] ?? '')),
        'created_at' => $hasNewUpload
            ? time()
            : (int)($sessionValues['attach_files'][$attachToken]['created_at'] ?? time()),
        'files' => $savedFiles,
    ];

    $sessionValues['attach_file'] = $savedFiles;
    $request['attach_token'] = $attachToken;
    apply_attached_form_values($request, $savedFiles);

    return $savedFiles;
}

/**
 * attach tokenに紐づく一時保存ファイルとセッション情報を破棄。
 *
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @param array<string, mixed> $request アプリ内リクエスト配列。
 * @return void
 */
function discard(array $config, array &$request): void
{
    // ---------------------------------------------
    // attach token取得
    // ---------------------------------------------
    $attachToken = normalize_attach_token((string)($request['attach_token'] ?? ''));
    $sessionValues =& $request['session_values'];

    if ($attachToken === '' || !isset($sessionValues['attach_files'][$attachToken])) {
        unset($sessionValues['attach_file']);
        $request['attach_token'] = '';
        return;
    }

    // ---------------------------------------------
    // 一時保存ファイル削除
    // ---------------------------------------------
    $attachEntry = $sessionValues['attach_files'][$attachToken];

    if (is_array($attachEntry)) {
        delete_attached_files($config, (array)($attachEntry['files'] ?? []));
    }

    unset($sessionValues['attach_files'][$attachToken], $sessionValues['attach_file']);
    $request['attach_token'] = '';
}

/**
 * 添付保存先に残る一時ファイルのうち、保持秒数を超えた旧ファイルを削除。
 *
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @param int $survivalSeconds 一時ファイルの保持秒数。
 * @return void
 */
function refresh_uploads(array $config, int $survivalSeconds = 3600): void
{
    $uploadDirectory = upload_directory($config);

    // ---------------------------------------------
    // 保存先存在判定
    // ---------------------------------------------
    if (!is_dir($uploadDirectory)) {
        return;
    }

    // ---------------------------------------------
    // 期限切れ一時ファイル削除
    // ---------------------------------------------
    foreach (glob($uploadDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $storedPath) {
        if (!preg_match('/\d+\.\d{4}$/', $storedPath)) {
            continue;
        }

        if ((time() - filemtime($storedPath)) > $survivalSeconds) {
            unlink($storedPath);
        }
    }
}

/**
 * セッション内の期限切れattach tokenを削除。
 *
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @param array<string, mixed> $sessionValues セッション値。
 * @param int $survivalSeconds 一時ファイルの保持秒数。
 * @return void
 */
function refresh_attach_tokens(array $config, array &$sessionValues, int $survivalSeconds = 3600): void
{
    // ---------------------------------------------
    // attach token存在判定
    // ---------------------------------------------
    if (!isset($sessionValues['attach_files']) || !is_array($sessionValues['attach_files'])) {
        $sessionValues['attach_files'] = [];
        return;
    }

    // ---------------------------------------------
    // 期限切れattach token削除
    // ---------------------------------------------
    foreach ($sessionValues['attach_files'] as $attachToken => $attachEntry) {
        if (
            normalize_attach_token((string)$attachToken) === ''
            || !is_array($attachEntry)
            || attach_entry_expired($attachEntry, $survivalSeconds)
        ) {
            delete_attached_files($config, is_array($attachEntry) ? (array)($attachEntry['files'] ?? []) : []);
            unset($sessionValues['attach_files'][$attachToken]);
        }
    }
}

/**
 * root配下のphp/var/uploadsを添付一時保存先として解決。
 *
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @return string 添付一時保存先ディレクトリ。
 */
function upload_directory(array $config): string
{
    // ---------------------------------------------
    // 保存先パス構築
    // ---------------------------------------------
    return ($config['_root_dir'] ?? dirname(__DIR__, 3)) . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'uploads';
}

/**
 * POSTされたattach tokenを安全な形式へ正規化。
 *
 * @param string $attachToken POSTされたattach token。
 * @return string 正規化済みattach token。
 */
function normalize_attach_token(string $attachToken): string
{
    // ---------------------------------------------
    // token形式判定
    // ---------------------------------------------
    return preg_match('/\A[a-f0-9]{32}\z/u', $attachToken) === 1
        ? $attachToken
        : '';
}

/**
 * セッション内で重複しないattach tokenを生成。
 *
 * @param array<string, mixed> $sessionValues セッション値。
 * @return string 生成したattach token。
 * @throws \Random\RandomException 乱数生成に失敗した場合。
 */
function create_attach_token(array &$sessionValues): string
{
    // ---------------------------------------------
    // token生成
    // ---------------------------------------------
    if (!isset($sessionValues['attach_files']) || !is_array($sessionValues['attach_files'])) {
        $sessionValues['attach_files'] = [];
    }

    do {
        $attachToken = bin2hex(random_bytes(16));
    } while (isset($sessionValues['attach_files'][$attachToken]));

    return $attachToken;
}

/**
 * attach tokenに紐づく保存済み添付ファイルを取得。
 *
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @param array<string, mixed> $request アプリ内リクエスト配列。
 * @param string $attachToken attach token。
 * @return array<string, array<string, mixed>> 保存済み添付ファイル情報。
 */
function stored_files(array $config, array &$request, string $attachToken): array
{
    // ---------------------------------------------
    // attach token保存情報取得
    // ---------------------------------------------
    $sessionValues =& $request['session_values'];
    $attachEntry = $sessionValues['attach_files'][$attachToken] ?? null;

    if (!valid_attach_entry($attachEntry, $config, $request)) {
        if (is_array($attachEntry)) {
            delete_attached_files($config, (array)($attachEntry['files'] ?? []));
        }

        unset($sessionValues['attach_files'][$attachToken]);
        return [];
    }

    $storedFiles = [];

    foreach ((array)$attachEntry['files'] as $fieldName => $storedFile) {
        if (!is_array($storedFile)) {
            continue;
        }

        $storedPath = attached_file_path($config, $storedFile);

        if ($storedPath === '' || !is_file($storedPath)) {
            continue;
        }

        $storedFile['path'] = $storedPath;
        $storedFiles[(string)$fieldName] = $storedFile;
    }

    if ($storedFiles === []) {
        unset($sessionValues['attach_files'][$attachToken]);
    }

    return $storedFiles;
}

/**
 * attach token保存情報が利用可能か判定。
 *
 * @param mixed $attachEntry attach token保存情報。
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @param array<string, mixed> $request アプリ内リクエスト配列。
 * @param int $survivalSeconds 一時ファイルの保持秒数。
 * @return bool 利用可能な場合はtrue。
 */
function valid_attach_entry(mixed $attachEntry, array $config, array $request, int $survivalSeconds = 3600): bool
{
    // ---------------------------------------------
    // 保存情報構造判定
    // ---------------------------------------------
    if (!is_array($attachEntry) || attach_entry_expired($attachEntry, $survivalSeconds)) {
        return false;
    }

    $entryConfigId = (string)($attachEntry['f2m_id'] ?? '');
    $requestConfigId = (string)($request['f2m_id'] ?? ($config['_id'] ?? ''));

    return $entryConfigId !== '' && hash_equals($entryConfigId, $requestConfigId);
}

/**
 * attach token保存情報が保持秒数を超えているか判定。
 *
 * @param array<string, mixed> $attachEntry attach token保存情報。
 * @param int $survivalSeconds 一時ファイルの保持秒数。
 * @return bool 期限切れの場合はtrue。
 */
function attach_entry_expired(array $attachEntry, int $survivalSeconds): bool
{
    // ---------------------------------------------
    // 作成時刻判定
    // ---------------------------------------------
    $createdAt = (int)($attachEntry['created_at'] ?? 0);

    return $createdAt < 1 || (time() - $createdAt) > $survivalSeconds;
}

/**
 * アップロードファイルが選択されているか判定。
 *
 * @param array<string, mixed> $uploadedFile アップロードファイル値。
 * @return bool ファイルが選択されている場合はtrue。
 */
function file_selected(array $uploadedFile): bool
{
    // ---------------------------------------------
    // アップロード有無判定
    // ---------------------------------------------
    return (int)($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
        && (int)($uploadedFile['size'] ?? 0) > 0;
}

/**
 * 保存済み添付ファイル名をフォーム値へ反映。
 *
 * @param array<string, mixed> $request アプリ内リクエスト配列。
 * @param array<string, array<string, mixed>> $savedFiles 保存済み添付ファイル情報。
 * @return void
 */
function apply_attached_form_values(array &$request, array $savedFiles): void
{
    $sessionValues =& $request['session_values'];

    // ---------------------------------------------
    // ファイル名反映
    // ---------------------------------------------
    foreach ($savedFiles as $fieldName => $savedFile) {
        $fileName = (string)($savedFile['name'] ?? '');

        $request['form_fields'][$fieldName] = $fileName;
        $sessionValues['form'][$fieldName] = $fileName;
    }
}

/**
 * 保存済み添付ファイルの実体パスを取得。
 *
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @param array<string, mixed> $storedFile 保存済み添付ファイル情報。
 * @return string 添付ファイル実体パス。
 */
function attached_file_path(array $config, array $storedFile): string
{
    // ---------------------------------------------
    // 保存ファイル名優先解決
    // ---------------------------------------------
    $storedName = (string)($storedFile['fname'] ?? '');

    if ($storedName !== '') {
        return upload_directory($config) . DIRECTORY_SEPARATOR . basename($storedName);
    }

    // ---------------------------------------------
    // 旧保存情報パス解決
    // ---------------------------------------------
    $storedPath = (string)($storedFile['path'] ?? '');

    if ($storedPath === '') {
        return '';
    }

    $uploadDirectory = realpath(upload_directory($config));
    $realStoredPath = realpath($storedPath);

    if ($uploadDirectory === false || $realStoredPath === false) {
        return '';
    }

    return str_starts_with($realStoredPath, $uploadDirectory . DIRECTORY_SEPARATOR)
        ? $realStoredPath
        : '';
}

/**
 * 保存済み添付ファイル群を削除。
 *
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @param array<string, mixed> $savedFiles 保存済み添付ファイル情報。
 * @return void
 */
function delete_attached_files(array $config, array $savedFiles): void
{
    // ---------------------------------------------
    // 添付ファイル削除
    // ---------------------------------------------
    foreach ($savedFiles as $savedFile) {
        delete_attached_file($config, is_array($savedFile) ? $savedFile : []);
    }
}

/**
 * 保存済み添付ファイルを削除。
 *
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @param array<string, mixed> $savedFile 保存済み添付ファイル情報。
 * @return void
 */
function delete_attached_file(array $config, array $savedFile): void
{
    // ---------------------------------------------
    // 添付ファイル削除
    // ---------------------------------------------
    $storedPath = attached_file_path($config, $savedFile);

    if ($storedPath !== '' && is_file($storedPath)) {
        unlink($storedPath);
    }
}

/**
 * タイムスタンプと乱数から、保存先内で重複しない一時ファイル名を生成。
 *
 * @param string $uploadDirectory 添付一時保存先ディレクトリ。
 * @return string 生成した一時ファイル名。
 * @throws \Random\RandomException 乱数生成に失敗した場合。
 */
function temporary_filename(string $uploadDirectory): string
{
    // ---------------------------------------------
    // 重複しない一時ファイル名生成
    // ---------------------------------------------
    do {
        $fileName = sprintf('%s.%04d', time(), random_int(0, 9999));
        $storedPath = $uploadDirectory . DIRECTORY_SEPARATOR . $fileName;
    } while (file_exists($storedPath));

    return $fileName;
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
