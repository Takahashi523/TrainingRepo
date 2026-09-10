<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait ResolvesSort
{
    /**
     * ソートを sort×order のペア単位で検証する。
     * $sortOptions（コントローラ側で定義する許可組の配列。SSOT）に一致するペアだけ採用し、
     * 無ければ先頭（デフォルト）へフォールバックする。
     * これにより仕様外の sort×order の組み合わせを弾き、UI の選択肢と完全に一致させる。
     *
     * @param  array<int, array{sort: string, order: string, label: string}>  $sortOptions
     * @return array{0: string, 1: string} [$sort, $order]
     */
    protected function resolveSort(Request $request, array $sortOptions): array
    {
        $sortInput = (string) $request->input('sort', '');
        $orderInput = strtolower((string) $request->input('order', ''));

        foreach ($sortOptions as $opt) {
            if ($opt['sort'] === $sortInput && $opt['order'] === $orderInput) {
                return [$opt['sort'], $opt['order']];
            }
        }

        return [$sortOptions[0]['sort'], $sortOptions[0]['order']];
    }
}
