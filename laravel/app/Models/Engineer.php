<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Engineer extends Model
{
    use HasFactory;

    public const PHASES = [
        ['key' => 'proc_requirements',  'name' => '要件定義'],
        ['key' => 'proc_basic_design',  'name' => '基本設計'],
        ['key' => 'proc_detail_design', 'name' => '詳細設計'],
        ['key' => 'proc_development',   'name' => '開発'],
        ['key' => 'proc_testing',       'name' => 'テスト'],
        ['key' => 'proc_maintenance',   'name' => '保守・運用'],
    ];

    public const WORK_STYLES = [
        ['key' => 'onsite', 'name' => '常駐'],
        ['key' => 'hybrid', 'name' => '一部リモート可'],
        ['key' => 'remote', 'name' => 'フルリモート'],
    ];

    /**
     * 人材ステータスの許容値（SSOT）。value => label。
     * 共有バリデーション（App\Validation\EngineerRules）・CSV 入出力はこの定数のみを参照し、
     * ステータスコードの二重管理を避ける。
     * （EngineerController は表示用に別途 private 定数を保持しているが、
     *  将来的には本定数へ寄せられる。本作業では横断リファクタを避け参照元は追加しない）
     */
    public const STATUSES = [
        ['value' => 'proposable',     'label' => '提案可'],
        ['value' => 'interviewing',   'label' => '面談中'],
        ['value' => 'not_proposable', 'label' => '提案不可'],
    ];

    protected $fillable = [
        'name', 'name_kana', 'birth_date', 'nearest_station', 'nearest_line',
        'available_from', 'has_negotiation_exp', 'desired_rate', 'appeal_note',
        'remarks', 'status', 'ai_summary', 'ai_summary_generated_at',
        'main_user_id', 'sub_user_id',
        'proc_requirements', 'proc_basic_design', 'proc_detail_design',
        'proc_development', 'proc_testing', 'proc_maintenance',
        'work_style_onsite', 'work_style_hybrid', 'work_style_remote',
    ];

    protected function casts(): array
    {
        return [
            'has_negotiation_exp' => 'boolean',
            'proc_requirements' => 'boolean',
            'proc_basic_design' => 'boolean',
            'proc_detail_design' => 'boolean',
            'proc_development' => 'boolean',
            'proc_testing' => 'boolean',
            'proc_maintenance' => 'boolean',
            'work_style_onsite' => 'boolean',
            'work_style_hybrid' => 'boolean',
            'work_style_remote' => 'boolean',
            // Python(E2)が返す ISO8601 文字列（+09:00 / Z / オフセット無し）を DATETIME カラムへ
            // 生格納すると、MySQL の session TZ とオフセットの組合せ次第でズレ／strict エラー（1292）に
            // なる。datetime キャストで一旦 Carbon へ正規化してから保存し、読み戻しも ISO8601 に揃える。
            'ai_summary_generated_at' => 'datetime',
        ];
    }

    /**
     * 年齢（birth_date から算出）。birth_date が null なら null。
     *
     * 一覧（EngineerListResource）と詳細・マッチング（EngineerResource）で同一算出をしていたため、
     * モデルのアクセサに集約して SSOT 化する（tasklist 9-3）。
     */
    protected function age(): Attribute
    {
        return Attribute::make(
            get: fn (): ?int => $this->birth_date
                ? Carbon::parse($this->birth_date)->age
                : null,
        );
    }

    /**
     * 稼働可能時期の表示ラベル（例「2026/08/01〜」）。available_from が null なら「未定」。
     *
     * age と同様、一覧・詳細・マッチングで重複していた表示ロジックをモデルに集約する（tasklist 9-3）。
     */
    protected function availableLabel(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->available_from
                ? Carbon::parse($this->available_from)->format('Y/m/d').'〜'
                : '未定',
        );
    }

    public function skills(): HasMany
    {
        return $this->hasMany(EngineerSkill::class);
    }

    /**
     * この人材に紐づくパイプライン（進捗）。
     * 人材削除時に FK で連鎖削除されるため、詳細画面の削除確認で件数警告に使う（DELETE #7）。
     */
    public function pipelines(): HasMany
    {
        return $this->hasMany(Pipeline::class);
    }

    public function mainUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'main_user_id');
    }

    public function subUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sub_user_id');
    }

    /**
     * 指定ユーザーがメインまたはサブで担当している人材に絞り込む。
     * ダッシュボードの「自分担当」集計軸（QA #70）で使用する共通スコープ。
     */
    public function scopeAssignedTo(Builder $query, int $userId): Builder
    {
        return $query->where(function (Builder $q) use ($userId) {
            $q->where('main_user_id', $userId)
                ->orWhere('sub_user_id', $userId);
        });
    }
}
