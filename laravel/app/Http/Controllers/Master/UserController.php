<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeleteUserRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;

/**
 * マスタ管理：ユーザーの追加・編集・削除。
 * 認可は admin ミドルウェア、バリデーション・業務ガードは FormRequest、
 * 永続化・並行制御は UserService が担い、本コントローラは委譲のみ行う。
 */
class UserController extends Controller
{
    public function __construct(private readonly UserService $userService) {}

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->userService->store($request->validated());

        return redirect()->route('master.index')->with('success', 'ユーザーを追加しました。');
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->userService->update($user, $request->validated());

        return redirect()->route('master.index')->with('success', 'ユーザーを更新しました。');
    }

    public function destroy(DeleteUserRequest $request, User $user): RedirectResponse
    {
        $this->userService->delete($user);

        return redirect()->route('master.index')->with('success', 'ユーザーを削除しました。');
    }
}
