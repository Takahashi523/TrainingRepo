import { useCallback, useRef, useState } from 'react';
import { FieldKind, getValidityError } from '@/lib/inputValidity';

/**
 * ネイティブ入力（NumberInput / DateInput）の silent rejection を、
 * onBlur（リアルタイム表示）と送信時（validateAll）の2点で検出する共通フック（Issue #33 / 案B）。
 *
 * <form> を使わない Inertia useForm 構成では、ブラウザ標準の reportValidity() に頼れないため、
 * validateAll() が「送信直前に全入力の validity を総ざらいして送信を止める」役割を JS で担う。
 *
 * 使い方:
 *   const { fieldProps, errors, validateAll } = useClientValidity();
 *   <NumberInput value={v} onChange={setV} {...fieldProps('headcount', 'number')} />
 *   // FormRow error={errors.headcount ?? form.errors.headcount}
 *   // 送信: if (!validateAll()) return; form.post(...)
 */
export function useClientValidity() {
    // name -> { 実 DOM 要素, 種別 }。ref コールバックで登録される。
    const refs = useRef<
        Record<string, { el: HTMLInputElement | null; kind: FieldKind }>
    >({});
    const [errors, setErrors] = useState<Record<string, string>>({});

    const setError = useCallback((name: string, msg: string | null) => {
        setErrors((prev) => {
            if (msg) {
                if (prev[name] === msg) return prev;
                return { ...prev, [name]: msg };
            }
            if (!(name in prev)) return prev;
            const next = { ...prev };
            delete next[name];
            return next;
        });
    }, []);

    const fieldProps = useCallback(
        (name: string, kind: FieldKind) => ({
            // ref: 実 DOM を登録（条件付き非表示欄では el=null になり validateAll でスキップ）。
            ref: (el: HTMLInputElement | null) => {
                refs.current[name] = { el, kind };
            },
            // onBlur: 離脱時にその欄だけ検証してエラー表示を更新。
            onBlur: () => {
                const entry = refs.current[name];
                setError(
                    name,
                    entry?.el ? getValidityError(entry.el, { kind: entry.kind }) : null,
                );
            },
        }),
        [setError],
    );

    /**
     * 送信直前ガード。登録済みの全入力を再検証し、不正があれば errors を更新して
     * 先頭の不正欄へフォーカスし false を返す（呼び出し側は送信を中止する）。
     */
    const validateAll = useCallback((): boolean => {
        const next: Record<string, string> = {};
        for (const [name, entry] of Object.entries(refs.current)) {
            if (!entry.el) continue;
            const msg = getValidityError(entry.el, { kind: entry.kind });
            if (msg) next[name] = msg;
        }
        setErrors(next);
        const firstInvalid = Object.keys(next)[0];
        if (firstInvalid) {
            refs.current[firstInvalid]?.el?.focus();
            return false;
        }
        return true;
    }, []);

    const clearError = useCallback(
        (name: string) => setError(name, null),
        [setError],
    );

    return { fieldProps, errors, validateAll, clearError };
}
