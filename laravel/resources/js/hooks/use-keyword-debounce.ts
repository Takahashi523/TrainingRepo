import { useDebouncedEffect } from '@/hooks/use-debounced-effect';
import { useEffect, useState } from 'react';

/** フリーワード検索の待機時間。全一覧画面で統一する。 */
export const KEYWORD_DEBOUNCE_MS = 300;

type UseKeywordDebounceArgs = {
    /** サーバー props の適用済みキーワード（`filters.keyword`） */
    appliedKeyword: string;
    /** 待機後に送る検索。画面ごとの `visit` をそのまま渡す */
    onDebounced: (keyword: string) => void;
    delayMs?: number;
};

type UseKeywordDebounceResult = {
    /** 入力欄に表示する値 */
    keywordInput: string;
    /** 入力欄の onChange。変更から `delayMs` 後に `onDebounced` が走る */
    setKeywordInput: (value: string) => void;
    /**
     * キーワードを含む条件を別経路で即時送信する直前に呼ぶ
     * （「すべてクリア」「保存済み条件の適用」）。
     * 入力欄の同期と保留デバウンスの無効化だけを行い、**送信はしない**
     * （`visit` の形が画面ごとに違うため、送信は呼び出し側の責務）。
     */
    applyKeyword: (keyword: string) => void;
};

/**
 * 一覧画面のフリーワード検索（入力欄 state ＋ props 追従 ＋ デバウンス）を共通化するフック（issue #38）。
 *
 * 人材一覧・案件一覧・進捗管理（進行中／完了済み）で同じ実装が重複しており、
 * 「すべてクリア」等との競合対策（`applyKeyword`）が一部の画面にしか入っていない状態を作っていた。
 * レースの機序と抑止の規則は {@link useDebouncedEffect} 側に集約している。
 *
 * 使い方：
 * ```tsx
 * const { keywordInput, setKeywordInput, applyKeyword } = useKeywordDebounce({
 *     appliedKeyword: filters.keyword,
 *     onDebounced: (keyword) => visit({ keyword, page: 1 }),
 * });
 *
 * const handleClearAll = () => {
 *     applyKeyword('');            // 保留中のデバウンスを無効化してから
 *     visit({ ...全条件空, page: 1 }); // クリアを1リクエストに集約する
 * };
 * ```
 */
export function useKeywordDebounce({
    appliedKeyword,
    onDebounced,
    delayMs = KEYWORD_DEBOUNCE_MS,
}: UseKeywordDebounceArgs): UseKeywordDebounceResult {
    // 入力欄はデバウンスのために state を分離し、サーバー側 filters.keyword と切り離す。
    const [keywordInput, setKeywordInput] = useState(appliedKeyword);

    // 戻る／進む・外部からの遷移でサーバー側の条件が変わったら入力欄を追従させる。
    useEffect(() => {
        setKeywordInput(appliedKeyword);
    }, [appliedKeyword]);

    const { suppressNextRun } = useDebouncedEffect(
        () => {
            // 適用済みと同じ語なら送るものが無い（props 追従で入力欄が書き換わった直後など）。
            if (keywordInput === appliedKeyword) return;
            onDebounced(keywordInput);
        },
        [keywordInput],
        delayMs,
    );

    const applyKeyword = (keyword: string) => {
        // 「入力欄がこの後どの値になるか」だけを渡す。値が変わるか否かで保留タイマーの
        // 消し方が変わる規則は useDebouncedEffect 側に閉じている。
        suppressNextRun([keyword]);
        if (keyword !== keywordInput) {
            setKeywordInput(keyword);
        }
    };

    return { keywordInput, setKeywordInput, applyKeyword };
}
