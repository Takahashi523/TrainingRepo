import { DependencyList, useEffect, useRef } from 'react';

export type DebouncedEffectControls = {
    /**
     * 別経路で同じ条件を確定送信する直前に呼び、保留中のデバウンスを無効化する。
     *
     * 受け取るのは「変わるか否か」ではなく**この直後に deps が取る値**。
     * 変化の有無の判定（＝無効化の手段の選択）は本フックが行うため、
     * 呼び出し側は deps の並びと同じ順で次の値を並べるだけでよい。
     *
     * @param nextDeps この呼び出しの直後に deps（＝入力欄の state）が取る値
     */
    suppressNextRun: (nextDeps: DependencyList) => void;
};

/**
 * 「別経路の確定送信で抑止できる」デバウンス effect（issue #38）。
 *
 * 一覧画面の検索条件は、入力欄（キーワード・終了日）を deps にした遅延 `visit` と、
 * 「すべてクリア」「保存済み条件の適用」のような**即時 `visit`** が同じ条件を書き換える。
 * このとき保留中のタイマーが即時 `visit` を追い越して発火すると、タイマー側が持つ
 * 古い条件が後から適用され、空にしたはずの条件が復活する（＝サーバー応答が
 * デバウンス待機時間より遅いときに顕在化するレース）。
 *
 * 保留タイマーの無効化は、**deps が変わるか否かで手段が変わる**のが罠になっている。
 *
 * - deps が変わる場合：effect の再実行に伴う cleanup が保留タイマーを破棄する。
 *   ただし再実行で**新しいタイマーが張られる**ため、それを1回だけ抑止する必要がある。
 * - deps が変わらない場合：effect が再実行されない＝cleanup も走らないため、
 *   保留タイマーが生き残る。こちらは直接 `clearTimeout` するしかない。
 *   （例：適用済みキーワードがある状態で入力欄を空にし、300ms 以内に「すべてクリア」を押す）
 *
 * この規則を呼び出し側に書かせると片方だけの対処になりやすいので、
 * `suppressNextRun(nextDeps)` として本フックに閉じ込める。呼び出し側は
 * 「これから deps がどの値になるか」だけを答え、どちらの手段を使うかは判定しない。
 *
 * 挙動：
 * - 初回マウントでは実行しない（マウント直後に検索リクエストを飛ばさない）
 * - deps が変わると `delayMs` 後に `effect` を実行し、再変化・アンマウントでは破棄する
 * - `effect` は毎レンダリング最新のものを使う（依存には含めない）
 *
 * @param effect 遅延実行する処理。呼び出し側のインライン関数でよい
 * @param deps 変化を監視する値（デバウンス対象の入力欄 state）
 * @param delayMs 待機時間。依存に含めないため、実行中に変えても次に deps が変わるまで反映されない（定数を渡す前提）
 */
export function useDebouncedEffect(
    effect: () => void,
    deps: DependencyList,
    delayMs: number,
): DebouncedEffectControls {
    // 常に最新の effect を指す ref。
    // effect をそのまま依存に入れると、呼び出し側のインライン関数が毎レンダリング別物になり、
    // レンダリングのたびにタイマーが張り直されてデバウンスが効かなくなる。
    const effectRef = useRef(effect);
    effectRef.current = effect;

    // 常に最新の deps を指す ref。suppressNextRun は event handler から呼ばれるため、
    // 「直前のコミット時点の deps」と引数の nextDeps を突き合わせて変化の有無を判定する。
    const depsRef = useRef(deps);
    depsRef.current = deps;

    const isInitialRun = useRef(true);
    const skipNextRun = useRef(false);
    // 保留中のタイマー。cleanup が走らない経路から破棄するために保持する。
    const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        if (isInitialRun.current) {
            isInitialRun.current = false;
            return;
        }
        if (skipNextRun.current) {
            skipNextRun.current = false;
            return;
        }
        const timer = setTimeout(() => {
            timerRef.current = null;
            effectRef.current();
        }, delayMs);
        timerRef.current = timer;
        return () => {
            clearTimeout(timer);
            if (timerRef.current === timer) {
                timerRef.current = null;
            }
        };
        // deps は呼び出し側が決める。effect / delayMs は ref・定数のため依存に含めない。
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, deps);

    const suppressNextRun = (nextDeps: DependencyList) => {
        // useEffect の依存比較と同じ規則（要素ごとの Object.is）で変化の有無を判定する。
        const currentDeps = depsRef.current;
        const depsWillChange =
            nextDeps.length !== currentDeps.length ||
            nextDeps.some((next, i) => !Object.is(next, currentDeps[i]));

        if (depsWillChange) {
            // 保留タイマーは effect 再実行時の cleanup が破棄する。
            // 新しく張られるタイマーだけを1回抑止する。
            skipNextRun.current = true;
            return;
        }
        // effect は再実行されない＝cleanup も走らないため、保留タイマーを直接破棄する。
        if (timerRef.current !== null) {
            clearTimeout(timerRef.current);
            timerRef.current = null;
        }
    };

    return { suppressNextRun };
}
