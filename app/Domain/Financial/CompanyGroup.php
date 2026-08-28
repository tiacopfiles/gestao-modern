<?php

namespace App\Domain\Financial;

/**
 * Empresas do cadastro legado que a conciliação trata como uma só, mesmo
 * sendo registros separados em `contas`/`contasareceber` (uma por banco).
 * Caso único hoje (Agrocolitti), configurado em `config/reconciliation.php`,
 * não um cadastro geral — não mexe em `contas` nem nas telas de contas a
 * pagar/receber, que continuam vendo cada id do jeito que sempre viram.
 */
class CompanyGroup
{
    /**
     * Os ids reais de empresa que formam o grupo de `$accountId`, quando ele
     * é o id canônico de um grupo — senão, `$accountId` sozinho.
     *
     * @return list<int>
     */
    public static function memberIds(int $accountId): array
    {
        foreach (self::grupos() as $grupo) {
            if ($grupo['canonical_id'] === $accountId) {
                return $grupo['member_ids'];
            }
        }

        return [$accountId];
    }

    /**
     * Todos os ids que representam um membro NÃO canônico de algum grupo —
     * usado para tirá-los do dropdown de criação de conciliação, já que o
     * canônico já representa o grupo inteiro ali.
     *
     * @return list<int>
     */
    public static function nonCanonicalMemberIds(): array
    {
        $ids = [];

        foreach (self::grupos() as $grupo) {
            foreach ($grupo['member_ids'] as $membroId) {
                if ($membroId !== $grupo['canonical_id']) {
                    $ids[] = $membroId;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /** O nome que a conciliação deve mostrar para o id canônico de um grupo. */
    public static function displayName(int $canonicalId): ?string
    {
        foreach (self::grupos() as $grupo) {
            if ($grupo['canonical_id'] === $canonicalId) {
                return $grupo['display_name'];
            }
        }

        return null;
    }

    /** @return list<array{canonical_id: int, member_ids: list<int>, display_name: string}> */
    private static function grupos(): array
    {
        return array_values(config('reconciliation.company_groups', []));
    }
}
