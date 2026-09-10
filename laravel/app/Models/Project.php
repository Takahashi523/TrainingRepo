<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
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
        ['value' => 'onsite', 'label' => '常駐'],
        ['value' => 'hybrid', 'label' => '一部リモート可'],
        ['value' => 'remote', 'label' => 'フルリモート'],
    ];

    public const COMMERCIAL_FLOWS = [
        ['value' => 'prime',     'label' => 'プライム'],
        ['value' => 'secondary', 'label' => '2次'],
        ['value' => 'tertiary',  'label' => '3次'],
        ['value' => 'other',     'label' => 'その他'],
    ];

    public const STATUSES = [
        ['value' => 'open',    'label' => '募集中'],
        ['value' => 'closed',  'label' => '終了'],
        ['value' => 'pending', 'label' => 'ペンディング'],
    ];

    public const INTERVIEW_COUNTS = [
        ['value' => 1, 'label' => '1回'],
        ['value' => 2, 'label' => '2回'],
        ['value' => 3, 'label' => '3回以上'],
    ];

    /**
     * 許可されたソートの組み合わせ（sort×order のペア＋表示ラベル）。SSOT。
     * ProjectController（一覧のソート検証・sortOptions props）と
     * SavedSearchService（保存条件の sort/order ホワイトリスト検証）の双方から参照する。
     * 先頭がデフォルト（created_at DESC）。
     */
    public const SORT_OPTIONS = [
        ['sort' => 'created_at', 'order' => 'desc', 'label' => '登録日順（新しい順）'],
        ['sort' => 'created_at', 'order' => 'asc',  'label' => '登録日順（古い順）'],
        ['sort' => 'updated_at', 'order' => 'desc', 'label' => '更新日順（新しい順）'],
        ['sort' => 'start_date', 'order' => 'asc',  'label' => '参画開始時期順'],
        ['sort' => 'rate_max',   'order' => 'desc', 'label' => '単価（高い順）'],
        ['sort' => 'rate_max',   'order' => 'asc',  'label' => '単価（低い順）'],
    ];

    /**
     * 案件 ENUM の表示ラベル（SSOT）。value => label。
     * フォーム選択肢（ProjectController）・マッチング結果の表示ラベル（MatchingResource）は
     * この定数のみを参照し、PHP/TS 双方でのラベル重複定義をなくす。
     */
    public const COMMERCIAL_FLOW_LABELS = [
        'prime' => 'プライム',
        'secondary' => '2次',
        'tertiary' => '3次',
        'other' => 'その他',
    ];

    public const WORK_STYLE_LABELS = [
        'onsite' => '常駐',
        'hybrid' => '一部リモート可',
        'remote' => 'フルリモート',
    ];

    public const STATUS_LABELS = [
        'open' => '募集中',
        'closed' => '終了',
        'pending' => 'ペンディング',
    ];

    protected $fillable = [
        'name',
        'client_name',
        'headcount',
        'start_date',
        'rate_min',
        'rate_max',
        'rate_note',
        'commercial_flow',
        'work_style',
        'work_location_line',
        'work_location_station',
        'interview_count',
        'proc_requirements',
        'proc_basic_design',
        'proc_detail_design',
        'proc_development',
        'proc_testing',
        'proc_maintenance',
        'negotiation_required',
        'description',
        'work_env',
        'billing_range',
        'remarks',
        'status',
        'main_user_id',
        'sub_user_id',
    ];

    protected $casts = [
        'proc_requirements' => 'boolean',
        'proc_basic_design' => 'boolean',
        'proc_detail_design' => 'boolean',
        'proc_development' => 'boolean',
        'proc_testing' => 'boolean',
        'proc_maintenance' => 'boolean',
        'negotiation_required' => 'boolean',
    ];

    /**
     * 参画開始時期の表示ラベル（例「2026/08/01〜」）。start_date が null なら「未定」。
     *
     * 一覧（ProjectListResource）・詳細（ProjectResource）・マッチング（MatchingResource）の
     * 3リソースに同一の生成ロジックがコピペされていたため、モデルのアクセサに集約する（#57）。
     * 人材側の Engineer::availableLabel と同じ実装形・同じ契約（常に文字列を返す）に揃えている。
     */
    protected function startLabel(): Attribute
    {
        return Attribute::make(
            // start_date は date キャストしていない文字列カラムのため Carbon::parse を通す
            get: fn (): string => $this->start_date
                ? Carbon::parse($this->start_date)->format('Y/m/d').'〜'
                : '未定',
        );
    }

    public function projectSkills(): HasMany
    {
        return $this->hasMany(ProjectSkill::class);
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
     * 指定ユーザーがメインまたはサブで担当している案件に絞り込む。
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
