<?php

namespace App\Domain\Banking;

final readonly class ParsedBankStatement
{
    /**
     * @param  list<ParsedBankStatementItem>  $items
     * @param  array<string, mixed>  $accountMetadata
     */
    public function __construct(
        public array $items,
        public array $accountMetadata,
        public ?string $periodStart,
        public ?string $periodEnd,
    ) {}
}
