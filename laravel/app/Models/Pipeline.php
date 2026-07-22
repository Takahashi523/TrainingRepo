<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pipeline extends Model
{
    use HasFactory;

    /** 1案件あたりのパイプライン追加上限（SSOT・QA #50。マッチング表示件数と同数）。 */
    public const MAX_PER_PROJECT = 5;

    /**
     * ステータス定義（SSOT）。
     * value => [label（表示名）, group（カンバングループキー）, is_terminal（終了か）]
     * Controller / Resource / Request はこの定数のみを参照し、ステータス表を重複定義しない（DRY）。
     */
    public const STATUSES = [
        // 進行中12種
        'proposed' => ['label' => '上位提案',         'group' => 'entry', 'is_terminal' => false],
        'applied_by_candidate' => ['label' => '求職者応募済み',   'group' => 'entry', 'is_terminal' => false],
        'applying' => ['label' => '応募中',           'group' => 'entry', 'is_terminal' => false],
        'first_scheduling' => ['label' => '一次調整中',       'group' => 'first_interview', 'is_terminal' => false],
        'first_waiting' => ['label' => '一次待ち',         'group' => 'first_interview', 'is_terminal' => false],
        'first_result_waiting' => ['label' => '一次結果待ち',     'group' => 'first_interview', 'is_terminal' => false],
        'final_scheduling' => ['label' => '最終調整中',       'group' => 'final_interview', 'is_terminal' => false],
        'final_waiting' => ['label' => '最終待ち',         'group' => 'final_interview', 'is_terminal' => false],
        'final_result_waiting' => ['label' => '最終結果待ち',     'group' => 'final_interview', 'is_terminal' => false],
        'offered' => ['label' => 'オファー',         'group' => 'offer',           'is_terminal' => false],
        'assign_waiting' => ['label' => 'アサイン承諾待ち', 'group' => 'offer',           'is_terminal' => false],
        'contracted' => ['label' => '成約',             'group' => 'offer',           'is_terminal' => false],
        // 終了4種
        'rejected' => ['label' => '不成立',           'group' => 'completed',       'is_terminal' => true],
        'closed' => ['label' => '募集終了',         'group' => 'completed',       'is_terminal' => true],
        'assign_declined' => ['label' => 'アサイン辞退',     'group' => 'completed',       'is_terminal' => true],
        'declined' => ['label' => '辞退',             'group' => 'completed',       'is_terminal' => true],
    ];

    /**
     * カンバンの4グループ（固定・表示順）。
     */
    public const KANBAN_GROUPS = [
        ['key' => 'entry',           'label' => 'エントリー'],
        ['key' => 'first_interview', 'label' => '一次選考'],
        ['key' => 'final_interview', 'label' => '最終選考'],
        ['key' => 'offer',           'label' => 'オファー'],
    ];

    /**
     * マッチングランク（固定4値）。
     */
    public const RANKS = [
        ['value' => 'A', 'label' => 'A', 'range' => '80点以上'],
        ['value' => 'B', 'label' => 'B', 'range' => '65〜79点'],
        ['value' => 'C', 'label' => 'C', 'range' => '50〜64点'],
        ['value' => 'D', 'label' => 'D', 'range' => '49点以下'],
    ];

    protected $fillable = [
        'engineer_id', 'project_id', 'status',
        'match_score', 'match_rank',
        'ai_score_reason', 'ai_comment', 'ai_missing',
        'client_comment', 'ng_reason',
        'next_action_date', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'next_action_date' => 'date',
            'ended_at' => 'datetime',
        ];
    }

    public function engineer(): BelongsTo
    {
        return $this->belongsTo(Engineer::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * 進行中ステータスの値一覧（is_terminal=false）。
     *
     * @return array<int, string>
     */
    public static function inProgressValues(): array
    {
        return array_keys(array_filter(
            self::STATUSES,
            fn (array $meta) => ! $meta['is_terminal']
        ));
    }

    /**
     * 終了ステータスの値一覧（is_terminal=true）。
     *
     * @return array<int, string>
     */
    public static function terminalValues(): array
    {
        return array_keys(array_filter(
            self::STATUSES,
            fn (array $meta) => $meta['is_terminal']
        ));
    }

    /**
     * 指定ステータスが終了（不可逆）かどうか。
     */
    public static function isTerminal(string $status): bool
    {
        return self::STATUSES[$status]['is_terminal'] ?? false;
    }

    /**
     * 指定ステータスの表示名を返す。未定義値はそのまま返す。
     */
    public static function label(string $status): string
    {
        return self::STATUSES[$status]['label'] ?? $status;
    }

    /**
     * match_score から match_rank（A/B/C/D）を判定する（SSOT）。
     * 閾値はスコアリングロジック設計書（PR #12）§3.3・本モデルの RANKS 定数と整合させる。
     * Python が付与した rank を信頼しつつ、生成受領時の検証・テストで本ヘルパーを基準にする。
     */
    public static function rankForScore(int $score): string
    {
        return match (true) {
            $score >= 80 => 'A',
            $score >= 65 => 'B',
            $score >= 50 => 'C',
            default => 'D',
        };
    }
}
