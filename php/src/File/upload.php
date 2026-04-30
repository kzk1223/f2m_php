<?php
declare(strict_types=1);

/**
 * 添付ファイル処理。
 */

namespace F2m\File;

use RuntimeException;

/**
 * 設定された添付項目のアップロードファイルを一時保存し、セッションへ保存情報を格納。
 *
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @param array<string, mixed> $request アプリ内リクエスト配列。
 * @return array<string, array<string, mixed>> 保存済み添付ファイル情報。
 * @throws RuntimeException 保存先作成またはアップロード保存に失敗した場合。
 */
function upload(array $config, array $request): array
{
    // ---------------------------------------------
    // 添付項目取得
    // ---------------------------------------------
    $attachFields = attach_fields($config);

    if ($attachFields === []) {
        return [];
    }

    $uploadDirectory = upload_directory($config);

    // ---------------------------------------------
    // 保存先ディレクトリ作成
    // ---------------------------------------------
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0775, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException(sprintf('%s create failed', $uploadDirectory));
    }

    // ---------------------------------------------
    // 添付ファイル保存
    // ---------------------------------------------
    $savedFiles = [];
    $sessionValues =& $request['session_values'];
    $sessionValues['attach_file'] = [];

    foreach ($attachFields as $fieldName) {
        $uploadedFile = $request['uploaded_files'][$fieldName] ?? null;

        if (!is_array($uploadedFile) || (int)($uploadedFile['size'] ?? 0) < 1) {
            continue;
        }

        if ((int)($uploadedFile['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('file upload errorr !!');
        }

        $storedName = temporary_filename($uploadDirectory);
        $storedPath = $uploadDirectory . DIRECTORY_SEPARATOR . $storedName;

        if (!move_uploaded_file((string)$uploadedFile['tmp_name'], $storedPath)) {
            throw new RuntimeException('file upload errorr !!');
        }

        $savedFile = $uploadedFile;
        $savedFile['fname'] = $storedName;
        $savedFile['path'] = $storedPath;

        $savedFiles[$fieldName] = $savedFile;
        $sessionValues['attach_file'][$fieldName] = $savedFile;
    }

    return $savedFiles;
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
