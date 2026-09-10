/**
 * 欠損値の表示語彙（SSOT）。
 *
 * 人材・案件・マッチングの3画面で「値が無い」ことの表現が
 * 「未設定 / 未定」「セグメントごと省略」「—」の3通りに分かれていたため、語彙をここに集約する（#57）。
 * 表示規則の全体像は docs/UI表示規約.md を参照。
 *
 * - unset（未設定）      : 登録時に入っているはずの情報が空
 * - undecided（未定）    : 後から決まり得る条件
 * - unassigned（未割当） : 割当の有無を表す固有語（サブ担当）
 *
 * 記号（「—」など）を使わないのは意図的な選択。営業担当が「埋めるべき項目」を判断できる必要があり、
 * かつ記号はスクリーンリーダーで意味が伝わらないため（docs/UI表示規約.md に理由を記載）。
 */

/** 欠損の種類ごとの表示語。 */
const EMPTY_KIND_TEXT = {
    unset: '未設定',
    undecided: '未定',
    unassigned: '未割当',
    uncalculated: '未算出',
} as const;

type EmptyKind = keyof typeof EMPTY_KIND_TEXT;

/**
 * 項目ごとの表示名と欠損の種類。
 * name はラベルなし文脈（型2＝圧縮メタ行）で「クライアント未設定」のように項目名を前置するために使う。
 */
export const EMPTY_FIELDS = {
    // --- 登録時に既知のはずの属性（未設定） ---
    age: { name: '年齢', kind: 'unset' },
    nearestStation: { name: '最寄駅', kind: 'unset' },
    nearestLine: { name: '路線', kind: 'unset' },
    clientName: { name: 'クライアント', kind: 'unset' },
    commercialFlow: { name: '商流', kind: 'unset' },
    skills: { name: 'スキル', kind: 'unset' },
    desiredRate: { name: '希望単価', kind: 'unset' },
    nextActionDate: { name: '次回', kind: 'unset' },
    // 詳細画面のみに出る項目（ラベル列＝型1 で使うため name は表示されないが、語の割り当てを明示する）
    negotiationExp: { name: '顧客折衝経験', kind: 'unset' },
    appealNote: { name: 'アピールポイント', kind: 'unset' },
    remarks: { name: '特記事項', kind: 'unset' },
    description: { name: '業務内容詳細', kind: 'unset' },
    workEnv: { name: '稼働環境', kind: 'unset' },
    billingRange: { name: '精算幅', kind: 'unset' },
    // 案件の勤務地は「勤務地」1語ではなく nearestStation / nearestLine の2トークンで描く。
    // 人材詳細と同じ粒度にそろえ、片方だけ入力された行でも欠けている側が読み取れるようにするため。
    // --- 後から決まり得る条件（未定） ---
    availableFrom: { name: '稼働可能時期', kind: 'undecided' },
    startDate: { name: '参画開始時期', kind: 'undecided' },
    rate: { name: '単価', kind: 'undecided' },
    headcount: { name: '募集人数', kind: 'undecided' },
    interviewCount: { name: '面談回数', kind: 'undecided' },
    workStyle: { name: '勤務形態', kind: 'undecided' },
    // --- 割当（未割当） ---
    mainUser: { name: '担当', kind: 'unassigned' },
    subUser: { name: 'サブ担当', kind: 'unassigned' },
    // --- 算出結果（未算出）。入力漏れではなく「まだ計算されていない」ことを表す ---
    matchScore: { name: 'マッチスコア', kind: 'uncalculated' },
    matchRank: { name: 'マッチランク', kind: 'uncalculated' },
    aiScoreReason: { name: 'スコア算出理由', kind: 'uncalculated' },
    // --- 進捗管理（完了タブ）の表示項目 ---
    ngReason: { name: 'NG理由', kind: 'unset' },
    endedAt: { name: '終了日', kind: 'unset' },
} as const satisfies Record<string, { name: string; kind: EmptyKind }>;

export type EmptyFieldKey = keyof typeof EMPTY_FIELDS;

/**
 * 欠損時の表示テキストを返す。
 *
 * @param key           対象項目
 * @param withFieldName 項目名を含めるか。
 *   - false（既定）… ラベル列（型1）やインラインラベル（型3）の中で使う場合。項目名はラベルが担うため
 *     値側では繰り返さない（例：「単価」ラベルの右に「未定」）。
 *   - true          … ラベルを持たない圧縮メタ行（型2）で使う場合。項目名が他に出ないため
 *     トークン側が担う（例：「単価未定」）。
 */
export function emptyText(key: EmptyFieldKey, withFieldName = false): string {
    const field = EMPTY_FIELDS[key];
    const kindText = EMPTY_KIND_TEXT[field.kind];

    return withFieldName ? `${field.name}${kindText}` : kindText;
}

/** 型2 で使う項目名（sr-only ラベル用）。 */
export function fieldName(key: EmptyFieldKey): string {
    return EMPTY_FIELDS[key].name;
}
