<?php

namespace App\Domain\Financial;

use App\Domain\Financial\Enums\FinancialTitleType;

final readonly class TitleIngestionData
{
    public function __construct(
        public string $sourceCode,
        public ?string $externalId,
        public FinancialTitleType $type,
        public string $issueDate,
        public string $dueDate,
        public int|string $originalAmount,
        public int|string $discountAmount = '0.00',
        public int|string $additionAmount = '0.00',
        public ?int $partyId = null,
        public ?string $partyType = null,
        public ?string $partyName = null,
        public ?string $documentNumber = null,
        public ?int $accountId = null,
        public ?int $categoryId = null,
        public ?int $costCenterId = null,
        public string $currency = 'BRL',
        public ?string $notes = null,
        public int $installmentCount = 1,
        public ?string $idempotencyKey = null,
        public ?string $legacyType = null,
        public ?int $legacyId = null,
    ) {}
}
