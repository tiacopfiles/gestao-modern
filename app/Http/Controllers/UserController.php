<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index()
    {
        return view('users.index', ['records' => User::orderBy('nome')->paginate(25)]);
    }

    public function create()
    {
        return view('users.form', ['record' => new User]);
    }

    public function edit(User $user)
    {
        return view('users.form', ['record' => $user]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, new User);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        return $this->save($request, $user);
    }

    public function destroy(User $user): RedirectResponse
    {
        abort_if($user->is(auth()->user()), 422, 'Você não pode excluir o próprio usuário.');
        $user->delete();
        Audit::record('users', $user->id, 'exclusao');

        return back()->with('success', 'Usuário excluído.');
    }

    private function save(Request $request, User $user): RedirectResponse
    {
        $paymentsConfigured = User::query()->where('pagamentos', '1')->exists();
        $commercialConfigured = User::query()->where('comercial', '1')->exists();
        $data = $request->validate(['nome' => ['required', 'string', 'max:255'], 'username' => ['required', 'string', 'max:255', 'unique:users,username,'.($user->id ?? 'NULL')], 'email' => ['required', 'email', 'max:255'], 'telefone' => ['nullable', 'string', 'max:40'], 'celular' => ['nullable', 'string', 'max:40'], 'empresa' => ['nullable', 'string', 'max:255'], 'password' => [$user->exists ? 'nullable' : 'required', 'string', 'min:8', 'max:72'], 'comercial' => ['nullable', 'boolean'], 'pagamentos' => ['nullable', 'boolean'], 'reconciliation_view' => ['nullable', 'boolean'], 'reconciliation_manage' => ['nullable', 'boolean'], 'reconciliation_close' => ['nullable', 'boolean'], 'reconciliation_reopen' => ['nullable', 'boolean'], 'reconciliation_export' => ['nullable', 'boolean'], 'reconciliation_admin' => ['nullable', 'boolean']]);
        foreach (['telefone', 'celular', 'empresa'] as $f) {
            $data[$f] = $data[$f] ?? '';
        }$data['comercial'] = $request->boolean('comercial') ? '1' : '0';
        $data['pagamentos'] = $request->boolean('pagamentos') ? '1' : '0';
        foreach (['reconciliation_view', 'reconciliation_manage', 'reconciliation_close', 'reconciliation_reopen', 'reconciliation_export', 'reconciliation_admin'] as $f) {
            $data[$f] = $request->boolean($f);
        }
        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
            $data['senha'] = '';
        } else {
            unset($data['password']);
        }$data['remember_token'] = '';
        $action = $user->exists ? 'alteracao' : 'inclusao';
        $user->fill($data)->save();
        if ((! $commercialConfigured && $request->boolean('comercial')) || (! $paymentsConfigured && $request->boolean('pagamentos'))) {
            $current = auth()->user();
            if (! $commercialConfigured && $request->boolean('comercial')) {
                $current->comercial = '1';
            }
            if (! $paymentsConfigured && $request->boolean('pagamentos')) {
                $current->pagamentos = '1';
            }
            $current->save();
        }
        Audit::record('users', $user->id, $action);

        return redirect()->route('users.index')->with('success', 'Usuário salvo.');
    }
}
