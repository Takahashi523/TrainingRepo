/**
 * CSV 入出力（WF_11 / api/08）のフロント型・定数。
 *
 * サーバーとの契約（design §2-6 / §3-3）：
 * - 画面 Props（GET /csv）：engineer_filter_options / project_filter_options
 * - インポート成功：flash.importResult = { resource, summary }
 * - インポート内容エラー：errors.importErrors（ImportError[] を JSON 文字列化したもの）
 * - インポートのファイルエラー：errors.file（単一メッセージ文字列）
 */

/** エクスポート絞り込みの選択肢（人材・案件共通の形。値の中身は各リソース由来）。 */
export type CsvFilterOptions = {
    statuses: { value: string; label: string }[];
    users: { id: number; name: string }[];
    work_styles: { key: string; name: string }[];
};

/** インポート成功サマリ（新規追加・更新の件数）。 */
export type ImportSummary = {
    total_rows: number;
    created: number;
    updated: number;
};

/**
 * インポートの1エラー（1つの (row, field) につき失敗メッセージを配列で保持）。
 * - row：ヘッダー行を1行目とする論理行番号。データ0行・上限超過など行に紐づかないものは null。
 * - field：DBフィールド名。列数不一致・ID重複など構造系エラーは null。
 */
export type ImportError = {
    row: number | null;
    field: string | null;
    messages: string[];
};

/** CSV の対象リソース種別。 */
export type CsvResource = 'engineers' | 'projects';

/** インポート成功時に flash.importResult へ載る値。 */
export type ImportResult = {
    resource: CsvResource;
    summary: ImportSummary;
};

/** GET /csv が返す画面 Props（CsvController@index）。 */
export type CsvIndexPageProps = {
    engineer_filter_options: CsvFilterOptions;
    project_filter_options: CsvFilterOptions;
    /** アップロード上限（バイト）。サーバー（CsvImportRequest::MAX_FILE_SIZE_KB）由来の SSOT。 */
    csv_max_upload_bytes: number;
};

/**
 * エラー表の「項目」列に出す日本語ラベル（field → 表示名）。
 *
 * ⚠️ SSOT は PHP 側の *CsvSchema（App\Support\Csv\EngineerCsvSchema / ProjectCsvSchema）の header 定義。
 * サーバーのエラーは field（DBカラム名）で返るため、WF_11 のとおり日本語ラベルで表示するにはこの対応表が要る。
 * PHP スキーマの header を変更したらここも同期すること（値がずれても field 名でフォールバック表示する）。
 */
export const CSV_FIELD_LABELS: Record<CsvResource, Record<string, string>> = {
    engineers: {
        id: 'id',
        name: '氏名',
        name_kana: '氏名カナ',
        birth_date: '生年月日',
        nearest_station: '最寄駅',
        nearest_line: '路線',
        available_from: '稼働可能時期',
        desired_rate: '希望単価（万円）',
        appeal_note: 'アピールポイント',
        ai_summary: 'AI要約', // エクスポート専用（インポート時は無視）。PHP 側 EngineerCsvSchema と同期。
        remarks: '特記事項',
        status: 'ステータス',
        main_user_id: '主担当ID',
        sub_user_id: 'サブ担当ID',
        work_style_onsite: '常駐可',
        work_style_hybrid: '一部リモート可',
        work_style_remote: 'フルリモート希望',
        proc_requirements: '要件定義経験',
        proc_basic_design: '基本設計経験',
        proc_detail_design: '詳細設計経験',
        proc_development: '開発経験',
        proc_testing: 'テスト経験',
        proc_maintenance: '保守運用経験',
        has_negotiation_exp: '顧客折衝経験',
    },
    projects: {
        id: 'id',
        name: '案件名',
        client_name: '顧客名',
        headcount: '募集人数',
        start_date: '参画開始時期',
        rate_min: '単価下限（万円）',
        rate_max: '単価上限（万円）',
        rate_note: '単価備考',
        commercial_flow: '商流',
        work_style: '稼働形態',
        work_location_line: '勤務地（路線）',
        work_location_station: '勤務地（最寄駅）',
        interview_count: '面談回数',
        negotiation_required: '顧客折衝経験要否',
        description: '業務内容詳細',
        work_env: '稼働環境',
        billing_range: '精算幅',
        remarks: '特記事項',
        status: 'ステータス',
        main_user_id: '主担当ID',
        sub_user_id: 'サブ担当ID',
        proc_requirements: '要件定義対象',
        proc_basic_design: '基本設計対象',
        proc_detail_design: '詳細設計対象',
        proc_development: '開発対象',
        proc_testing: 'テスト対象',
        proc_maintenance: '保守運用対象',
    },
};

/** field → 日本語ラベル（未知の field は field 名そのまま／null は「行全体」）。 */
export function csvFieldLabel(resource: CsvResource, field: string | null): string {
    if (field === null) return '行全体';
    return CSV_FIELD_LABELS[resource][field] ?? field;
}

/**
 * ヘッダーレベルのエラーか（行・項目バリデーションと出し分けるための判定）。
 *
 * サーバーはヘッダー不正（欠落/空/重複/未知）を行番号 1（ヘッダー行）で返し、かつ即中断するため
 * 他のエラーと混在しない。データ行のエラーは必ず行番号 2 以上になる（ヘッダーが1行目）。
 * よって「row === 1 を含むか」でヘッダーエラーかどうかを一意に判定できる。
 */
export function hasHeaderError(errors: ImportError[]): boolean {
    return errors.some((e) => e.row === 1);
}

/** エラー配列から「エラー行数」「メッセージ総数」を集計する（サマリ表示・バナー用）。 */
export function summarizeImportErrors(errors: ImportError[]): {
    errorRowCount: number;
    messageCount: number;
} {
    const rows = new Set<number>();
    let messageCount = 0;
    for (const e of errors) {
        if (e.row !== null) rows.add(e.row);
        messageCount += e.messages.length;
    }
    return { errorRowCount: rows.size, messageCount };
}

/**
 * 表示用にファイル名をサニタイズする（O-12）。
 * File.name はユーザー制御のため、視覚スプーフィングに使われる制御文字・双方向制御文字（U+202E 等）を
 * コードポイント判定で除去し、長すぎる名前は中央を省略する。表示専用（パス・FS 操作には一切使わない）。
 */
export function sanitizeFileName(name: string, maxLength = 60): string {
    // 除去対象のコードポイント（生の制御文字をソースに埋め込まず判定で扱う）：
    //  - C0 制御 (0x00-0x1F)・DEL/C1 制御 (0x7F-0x9F)
    //  - 双方向制御 (LRM/RLM 0x200E-0x200F, LRE-RLO 0x202A-0x202E, LRI-PDI 0x2066-0x2069)
    //  - ゼロ幅・BOM (0x200B-0x200D, 0xFEFF)
    const isRemovable = (cp: number): boolean =>
        cp <= 0x1f ||
        (cp >= 0x7f && cp <= 0x9f) ||
        cp === 0x200e ||
        cp === 0x200f ||
        (cp >= 0x202a && cp <= 0x202e) ||
        (cp >= 0x2066 && cp <= 0x2069) ||
        (cp >= 0x200b && cp <= 0x200d) ||
        cp === 0xfeff;

    let cleaned = '';
    for (const ch of name) {
        const cp = ch.codePointAt(0);
        if (cp !== undefined && !isRemovable(cp)) cleaned += ch;
    }
    cleaned = cleaned.trim();

    const safe = cleaned === '' ? 'ファイル' : cleaned;
    if (safe.length <= maxLength) return safe;
    const head = safe.slice(0, maxLength - 12);
    const tail = safe.slice(-8);
    return `${head}…${tail}`;
}

/** バイト数を人が読める単位に整形（WF_11 準拠：<1MB は KB、以上は MB）。 */
export function formatFileSize(bytes: number): string {
    if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))}KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)}MB`;
}
