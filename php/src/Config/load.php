<?php
declare(strict_types=1);

/**
 * 設定ファイル読込処理。
 */

namespace F2m\Config;

use RuntimeException;

/**
 * 設定TXTを読み込み、F2M_ID単位の設定配列へ変換。
 *
 * @param string $configPath 設定TXTのファイルパス。
 * @return array<string, array<string, mixed>> F2M_IDをキーにした設定配列。
 * @throws RuntimeException 設定TXTが存在しない場合。
 */
function load(string $configPath): array
{
    if (!is_file($configPath)) {
        throw new RuntimeException(sprintf('%s not found', $configPath));
    }

    // ---------------------------------------------
    // 設定ファイル読込
    // ---------------------------------------------
    $configs = [];
    $currentConfigId = null;
    $rootDirectory = dirname($configPath);

    $configText = config_text($configPath);

    foreach (preg_split('/\r\n|\r|\n/u', $configText) ?: [] as $record) {
        if (trim($record) === '' || preg_match('/^\s*#/u', $record)) {
            continue;
        }

        if (!preg_match('/^\s*([^#:\r\n]+?)\s*:\s*(.*?)\s*$/u', $record, $matches)) {
            continue;
        }

        $configKey = trim($matches[1]);
        $configValue = trim($matches[2]);

        if ($configKey === 'F2M_ID') {
            $currentConfigId = $configValue;
            $configs[$currentConfigId] = [
                '_id' => $currentConfigId,
                '_config_path' => $configPath,
                '_root_dir' => $rootDirectory,
            ];
            continue;
        }

        if ($currentConfigId === null) {
            continue;
        }

        $configs[$currentConfigId][$configKey] = normalize_value($configKey, $configValue);
    }

    return $configs;
}

/**
 * 設定TXTをUTF-8文字列として読み込み。
 *
 * @param string $configPath 設定TXTのファイルパス。
 * @return string UTF-8化済み設定文字列。
 * @throws RuntimeException 設定TXTを読み込めない場合。
 */
function config_text(string $configPath): string
{
    // ---------------------------------------------
    // 設定ファイル読込
    // ---------------------------------------------
    $rawConfigText = file_get_contents($configPath);

    if ($rawConfigText === false) {
        throw new RuntimeException(sprintf('%s read failed', $configPath));
    }

    // ---------------------------------------------
    // UTF-8 BOM判定
    // ---------------------------------------------
    if (str_starts_with($rawConfigText, "\xEF\xBB\xBF")) {
        return substr($rawConfigText, 3);
    }

    // ---------------------------------------------
    // UTF-8妥当性判定
    // ---------------------------------------------
    if (mb_check_encoding($rawConfigText, 'UTF-8')) {
        return $rawConfigText;
    }

    // ---------------------------------------------
    // 文字コード判定
    // ---------------------------------------------
    $detectedEncoding = mb_detect_encoding($rawConfigText, ['SJIS-win', 'SJIS'], true);

    if ($detectedEncoding === 'SJIS-win' || $detectedEncoding === 'SJIS') {
        return mb_convert_encoding($rawConfigText, 'UTF-8', $detectedEncoding);
    }

    // ---------------------------------------------
    // 旧設定互換フォールバック
    // ---------------------------------------------
    return mb_convert_encoding($rawConfigText, 'UTF-8', 'SJIS-win');
}

/**
 * 設定キーに応じて、カンマ区切り値や項目名設定を配列へ変換。
 *
 * @param string $configKey 設定キー名。
 * @param string $configValue 設定値。
 * @return mixed 正規化済み設定値。
 */
function normalize_value(string $configKey, string $configValue): mixed
{
    // ---------------------------------------------
    // 設定キー別変換
    // ---------------------------------------------
    return match ($configKey) {
        'F2M_CHK', 'F2M_CHK_EMAIL' => list_to_map($configValue),
        'F2M_JPNAME' => jp_name_map($configValue),
        'F2M_CHK_EQ' => comma_list($configValue),
        default => $configValue,
    };
}

/**
 * カンマ区切り文字列を空要素を除いた配列へ変換。
 *
 * @param string $configValue カンマ区切り設定値。
 * @return array<int, string> 分割済み文字列配列。
 */
function comma_list(string $configValue): array
{
    // ---------------------------------------------
    // 空要素除去
    // ---------------------------------------------
    return array_values(array_filter(
        array_map('trim', explode(',', $configValue)),
        static fn (string $item): bool => $item !== ''
    ));
}

/**
 * カンマ区切りの項目名を、存在判定用のキー配列へ変換。
 *
 * @param string $configValue カンマ区切り項目名。
 * @return array<string, int> 項目名をキーにした判定用配列。
 */
function list_to_map(string $configValue): array
{
    $fieldMap = [];

    // ---------------------------------------------
    // 項目名のキー化
    // ---------------------------------------------
    foreach (comma_list($configValue) as $fieldName) {
        $fieldMap[$fieldName] = 1;
    }

    return $fieldMap;
}

/**
 * name:表示名形式の設定値を、フォーム項目名と表示名の対応表へ変換。
 *
 * @param string $configValue カンマ区切りの項目表示名設定。
 * @return array<string, string> フォーム項目名をキーにした表示名配列。
 */
function jp_name_map(string $configValue): array
{
    $fieldLabels = [];

    // ---------------------------------------------
    // 項目名と表示名の分離
    // ---------------------------------------------
    foreach (comma_list($configValue) as $fieldSetting) {
        if (!preg_match('/^([^:]+?)\s*:\s*(.+)$/u', $fieldSetting, $matches)) {
            continue;
        }

        $fieldLabels[trim($matches[1])] = trim($matches[2]);
    }

    return $fieldLabels;
}
