<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedSearch extends Model
{
    /**
     * search_type の許容値（SSOT）。バリデーション（Rule::in）等はこの定数を参照する。
     */
    public const SEARCH_TYPES = ['engineer', 'project'];

    /**
     * user_id はコントローラー側で Auth::id() から明示的にセットすること。
     * クライアントから送信された user_id をそのまま代入してはいけない（DB設計書 §4 / QA #81確定）。
     */
    protected $fillable = ['user_id', 'name', 'search_type', 'conditions'];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function listForUser(int $userId, string $searchType)
    {
        return SavedSearch::where('user_id', $userId)
            ->where('search_type', $searchType)
            ->get(['id', 'name', 'conditions']);
    }
}
