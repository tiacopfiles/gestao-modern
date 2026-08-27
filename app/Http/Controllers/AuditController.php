<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function index(Request $request)
    {
        $query = AuditLog::query();
        if ($request->filled('table')) {
            $query->where('nome_tabela', 'like', '%'.$request->string('table').'%');
        }
        if ($request->filled('action')) {
            $query->where('tipo_alteracao', 'like', '%'.$request->string('action').'%');
        }
        if ($request->filled('from')) {
            $query->whereDate('data', '>=', $request->string('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('data', '<=', $request->string('to'));
        }
        $records = $query->orderByDesc('id_log')->paginate(40)->withQueryString();
        $users = User::withTrashed()->pluck('nome', 'id');

        return view('audit.index', compact('records', 'users'));
    }
}
