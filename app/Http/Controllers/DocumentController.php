<?php

namespace App\Http\Controllers;

use App\Models\Documento;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function store(Request $request, string $entity, int $id): RedirectResponse
    {
        abort_unless(in_array($entity, ['payable', 'receivable', 'movement', 'client', 'supplier'], true), 404);
        $data = $request->validate(['document' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,xls,xlsx,csv,doc,docx,txt']]);
        $file = $data['document'];
        $path = $file->store("documents/{$entity}/{$id}", 'local');
        $doc = Documento::create(['entidade' => $entity, 'registro_id' => $id, 'nome_original' => $file->getClientOriginalName(), 'caminho' => $path, 'mime' => $file->getMimeType(), 'tamanho' => $file->getSize(), 'usuario_id' => auth()->id()]);
        Audit::record('documentos_modernos', $doc->id, 'inclusao');

        return back()->with('success', 'Documento enviado com segurança.');
    }

    public function download(Documento $documento)
    {
        abort_unless(Storage::disk('local')->exists($documento->caminho), 404);

        return Storage::disk('local')->download($documento->caminho, $documento->nome_original);
    }

    public function destroy(Documento $documento): RedirectResponse
    {
        Storage::disk('local')->delete($documento->caminho);
        $documento->delete();
        Audit::record('documentos_modernos', $documento->id, 'exclusao');

        return back()->with('success', 'Documento removido.');
    }
}
