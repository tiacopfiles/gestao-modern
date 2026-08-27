<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

/**
 * Porta de entrada da aplicação.
 *
 * Existe como controller, e não como closure na rota, porque uma closure não
 * sobrevive ao `route:cache`: no servidor a raiz virou uma rota serializada que
 * respondia 405 a um GET legítimo, e quem digita o endereço sem o caminho cai
 * exatamente aí. Controller é serializável, então `route:cache` e `optimize`
 * ficam seguros.
 */
class HomeController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        return redirect()->route(auth()->check() ? 'dashboard' : 'login');
    }
}
