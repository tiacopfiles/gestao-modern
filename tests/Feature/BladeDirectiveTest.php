<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Diretiva Blade colada no fim de outra não compila — e falha em silêncio.
 *
 * O Blade só reconhece uma diretiva quando o caractere anterior NÃO é letra,
 * número ou `_`. Escrito `@endif@endsection`, o `f` antes do `@` faz o
 * `@endsection` passar batido: ele vai para a tela como texto e, pior, a seção
 * nunca é fechada — o conteúdo escapa do layout e a página inteira sai
 * desmontada, com o formulário aparecendo antes do menu.
 *
 * Foi assim que a tela de criar usuário quebrou. Nada acusa: não há erro, não
 * há exceção, e a view "compila" normalmente. Por isso a checagem é no arquivo.
 */
class BladeDirectiveTest extends TestCase
{
    private const VIEWS = __DIR__.'/../../resources/views';

    public function test_nenhuma_diretiva_blade_esta_colada_no_fim_de_outra(): void
    {
        $diretivas = 'if|else|elseif|endif|foreach|endforeach|forelse|empty|endforelse'
            .'|for|endfor|while|endwhile|section|endsection|show|yield|extends|include'
            .'|csrf|method|can|endcan|cannot|endcannot|unless|endunless|php|endphp'
            .'|isset|endisset|checked|selected|disabled|switch|endswitch|case|break'
            .'|default|json|class|style|props|once|endonce|auth|endauth|guest|endguest';

        $problemas = [];

        $arquivos = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::VIEWS, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($arquivos as $arquivo) {
            if (! str_ends_with($arquivo->getFilename(), '.blade.php')) {
                continue;
            }

            $conteudo = file_get_contents($arquivo->getPathname());

            if (preg_match_all("/[a-zA-Z0-9_](@(?:{$diretivas})\\b)/", $conteudo, $achados, PREG_OFFSET_CAPTURE)) {
                foreach ($achados[1] as [$diretiva, $posicao]) {
                    $linha = substr_count(substr($conteudo, 0, $posicao), "\n") + 1;
                    $relativo = str_replace(self::VIEWS.DIRECTORY_SEPARATOR, '', $arquivo->getPathname());
                    $problemas[] = "{$relativo}:{$linha} — {$diretiva} colado no caractere anterior";
                }
            }
        }

        $this->assertSame([], $problemas, implode(
            "\n",
            array_merge(
                ['Diretiva Blade grudada em letra/número não é compilada. Separe com espaço ou quebra de linha:'],
                $problemas,
            ),
        ));
    }
}
