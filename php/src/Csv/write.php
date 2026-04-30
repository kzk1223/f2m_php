<?php
declare(strict_types=1);

/**
 * CSV出力処理。
 */

namespace F2m\Csv;

use RuntimeException;

/**
 * F2M_CSV設定がある場合に、項目見出しとフォーム値をSJIS変換してCSVへ追記。
 * 表計算ソフトでの数式実行を避けるため、危険な先頭文字を持つ値は無害化。
 *
 * @param array<string, mixed> $config F2M_ID単位の設定配列。
 * @param array<string, mixed> $request アプリ内リクエスト配列。
 * @param array<string, mixed> $uploadedFiles 保存済み添付ファイル情報。
 * @return void
 * @throws RuntimeException CSV保存先作成またはファイルオープンに失敗した場合。
 */
function write(array $config, array $request, array $uploadedFiles = []): void
{
    // ---------------------------------------------
    // CSV設定判定
    // ---------------------------------------------
    if (empty($config['F2M_CSV'])) {
        return;
    }

    $csvPath = resolve_path($config, (string)$config['F2M_CSV']);
    $csvDirectory = dirname($csvPath);

    // ---------------------------------------------
    // CSV保存先作成
    // ---------------------------------------------
    if (!is_dir($csvDirectory) && !mkdir($csvDirectory, 0775, true) && !is_dir($csvDirectory)) {
        throw new RuntimeException(sprintf('%s create failed', $csvDirectory));
    }

    // ---------------------------------------------
    // CSV行構築
    // ---------------------------------------------
    $headerFields = [];
    $valueFields = [];
    $formFields = $request['form_fields'] ?? [];

    foreach (($config['F2M_JPNAME'] ?? []) as $fieldName => $fieldLabel) {
        $headerFields[] = mb_convert_encoding((string)$fieldLabel, 'SJIS', 'UTF-8');
        $valueFields[] = mb_convert_encoding(csv_safe_value((string)($formFields[$fieldName] ?? '')), 'SJIS', 'UTF-8');
    }

    // ---------------------------------------------
    // CSV書込
    // ---------------------------------------------
    $needsHeader = !file_exists($csvPath);
    $handle = fopen($csvPath, 'ab');

    if ($handle === false) {
        throw new RuntimeException(sprintf('%s open failed', $csvPath));
    }

    if ($needsHeader) {
        write_csv_row($handle, $headerFields);
    }

    write_csv_row($handle, $valueFields);
    fclose($handle);
}

/**
 * 表計算ソフトで数式として解釈され得るCSV値を無害化。
 *
 * @param string $fieldValue CSV出力値。
 * @return string 無害化済みCSV出力値。
 */
function csv_safe_value(string $fieldValue): string
{
    // ---------------------------------------------
    // CSVインジェクション対策
    // ---------------------------------------------
    if (preg_match('/^\s*[=+\-@]/u', $fieldValue) === 1 || preg_match('/^[\t\r\n]/', $fieldValue) === 1) {
        return "'" . $fieldValue;
    }

    return $fieldValue;
}

/**
 * 旧版のCSVエスケープ仕様に従い、必要な値をダブルクォートで囲んで一行出力。
 *
 * @param mixed $handle 書込対象ファイルハンドル。
 * @param array<int, string> $fields CSV出力値。
 * @return void
 */
function write_csv_row(mixed $handle, array $fields): void
{
    $escapedFields = [];

    // ---------------------------------------------
    // CSV値エスケープ
    // ---------------------------------------------
    foreach ($fields as $fieldValue) {
        $escapedValue = str_replace('"', '""', (string)$fieldValue);

        if (preg_match('/[,"\s]/', $escapedValue)) {
            $escapedValue = '"' . $escapedValue . '"';
        }

        $escapedFields[] = $escapedValue;
    }

    // ---------------------------------------------
    // CSV一行書込
    // ---------------------------------------------
    fwrite($handle, implode(',', $escapedFields) . "\n");
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
