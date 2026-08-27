<?php

namespace App\Application\Banking;

use App\Models\IntegrationClient;
use App\Models\SourceSystem;
use RuntimeException;

/**
 * Identidade interna usada quando a importação bancária vem da interface web,
 * e não de uma integração externa.
 *
 * `OfxImportService::import()` exige um `IntegrationClient` porque foi escrito
 * para o caminho da API (ADR-007), e o lote importado guarda essa referência —
 * é assim que a auditoria sabe de onde o fato bancário entrou. Em vez de
 * afrouxar o contrato do serviço para aceitar `null`, a importação manual ganha
 * um cliente próprio e explícito: o histórico continua respondendo "quem
 * trouxe este extrato" sem confundir upload humano com integração automática.
 *
 * Este cliente **não possui token utilizável**: `token_hash` é aleatório e
 * nunca é emitido a ninguém, e `active = false` impede que sirva para
 * autenticar chamadas na API. Ele existe apenas como rótulo de origem.
 */
class WebBankImportClient
{
    public const NAME = 'Importação manual (interface web)';

    public function resolve(): IntegrationClient
    {
        $source = SourceSystem::query()->where('code', 'BANK_IMPORT')->first();
        if (! $source) {
            throw new RuntimeException('Origem BANK_IMPORT ausente: rode as migrations/seed da Fase 1.');
        }

        $client = IntegrationClient::query()
            ->where('source_system_id', $source->id)
            ->where('name', self::NAME)
            ->first();

        if ($client) {
            return $client;
        }

        return IntegrationClient::query()->create([
            'source_system_id' => $source->id,
            'name' => self::NAME,
            'token_prefix' => 'webui',
            // Aleatório e descartado: nenhum token é emitido para este cliente.
            'token_hash' => hash('sha256', 'web-ui-bank-import|'.bin2hex(random_bytes(32))),
            'scopes' => ['bank-imports:write'],
            // Inativo de propósito: serve como origem de auditoria, nunca para
            // autenticar uma requisição de API.
            'active' => false,
        ]);
    }
}
