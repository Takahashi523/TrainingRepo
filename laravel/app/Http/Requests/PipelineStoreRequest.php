<?php

namespace App\Http\Requests;

use App\Models\Engineer;
use App\Models\Project;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * マッチング結果画面からのパイプライン生成（POST /pipelines）の形式バリデーション。
 *
 * 重複・上限（1案件5件）チェックは競合制御を伴うため PipelineService@create の
 * トランザクション内で行う（本 Request は形式検証に限定する）。
 * status は 'proposed' 固定（QA #49）・担当は engineers.main_user_id 参照（QA #83）のため
 * いずれもフロントから受け取らない。
 */
class PipelineStoreRequest extends FormRequest
{
    /**
     * 生成はロール不問（管理者・一般営業とも可）。auth ミドルウェアで保護する。
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'engineer_id' => ['required', 'integer', 'exists:engineers,id'],
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            // マッチング実行時点のスナップショット（サーバー側で再計算しない・QA #45）
            'match_score' => ['required', 'integer', 'between:0,100'],
            'match_rank' => ['required', 'in:A,B,C,D'],
            'ai_score_reason' => ['nullable', 'string'],
            'ai_comment' => ['nullable', 'string'],
            'ai_missing' => ['nullable', 'string'],
        ];
    }

    /**
     * 案件の掲載状態（status='open'）を追加検証する。
     *
     * スコアリングロジック設計書 §3.4 のとおり、マッチ対象は status='open' の案件に限る。
     * マッチング結果の表示〜追加ボタン押下の間に別ユーザーが案件を closed / pending へ変更した
     * stale ページからの追加を、書き込み経路（POST）でも弾く（表示側 GET は MatchingController で除外済み）。
     * exists 検証で既に project_id にエラーがある場合（未指定・存在しない）は二重表示を避けて何もしない。
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $projectId = $this->input('project_id');

            if ($projectId === null || $validator->errors()->has('project_id')) {
                return;
            }

            // 存在するが募集中(open)でない案件は追加不可。存在しない場合は exists 側で捕捉済み。
            $status = Project::whereKey($projectId)->value('status');

            if ($status !== null && $status !== 'open') {
                $validator->errors()->add(
                    'project_id',
                    'この案件は現在募集していないため、パイプラインに追加できません。'
                );
            }
        });
    }

    public function attributes(): array
    {
        return [
            'engineer_id' => '人材',
            'project_id' => '案件',
            'match_score' => 'マッチングスコア',
            'match_rank' => 'マッチングランク',
        ];
    }

    /**
     * バリデーション失敗時の遷移先を制御する。
     *
     * 1. 対象人材が存在しない（計算中〜表示中に管理者が削除した等）場合、既定の back リダイレクトだと
     *    削除済み人材のマッチング画面へ戻り route model binding が 404 になる。人材一覧へ誘導し
     *    フラッシュで理由を通知する（不親切な 404 回避）。
     * 2. 案件が「削除済み」「掲載停止(closed/pending)」の場合、既定の exists / after エラー文言より
     *    分かりやすい専用メッセージへ差し替える。ただし transport は field エラー（withErrors）に
     *    統一する：フロント（MatchDrawer）は errors バッグが空だと Inertia の onSuccess が発火して
     *    「追加済み」に楽観更新してしまうため、flash のみで返すと DB 未作成なのに成功扱いになる
     *    （Silent Success ＝誤「追加済み」）。マッチング結果は楽観更新（back時 results=null＋
     *    preserveState）でカードを消さずに保持するため、field エラーはドロワー内にそのまま表示され、
     *    重複・上限（PipelineService 側で withMessages）と挙動が揃う。
     *
     * 上記以外（スコア不正など）も従来どおり back でフィールドエラーをドロワーに表示する。
     */
    protected function failedValidation(Validator $validator): void
    {
        $engineerId = $this->input('engineer_id');

        if ($engineerId !== null && ! Engineer::whereKey($engineerId)->exists()) {
            throw new HttpResponseException(
                redirect()->route('engineers.index')
                    ->with('error', '対象の人材が見つかりません。削除された可能性があります。')
            );
        }

        // project_id が送られたうえで弾かれた＝「削除済み」または「掲載停止」。専用文言に差し替えつつ、
        // field エラー（withErrors）で返す。flash のみだと onSuccess が発火し誤「追加済み」になるため。
        $projectId = $this->input('project_id');

        if ($projectId !== null && $validator->errors()->has('project_id')) {
            $message = Project::whereKey($projectId)->exists()
                ? '選択した案件は現在募集していないため、パイプラインに追加できませんでした。'
                : '選択した案件が見つかりません。削除された可能性があります。';

            throw new HttpResponseException(
                redirect()->back()->withErrors(['project_id' => $message])
            );
        }

        parent::failedValidation($validator);
    }
}
