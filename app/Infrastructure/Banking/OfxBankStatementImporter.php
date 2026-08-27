<?php

namespace App\Infrastructure\Banking;

use App\Contracts\BankStatementImporter;
use App\Domain\Banking\BankTransactionData;
use App\Domain\Banking\Enums\BankTransactionDirection;
use App\Domain\Banking\Exceptions\BankImportInvalidFile;
use App\Domain\Banking\ParsedBankStatement;
use App\Domain\Banking\ParsedBankStatementItem;
use App\Domain\Financial\Money;
use Carbon\CarbonImmutable;
use Throwable;

class OfxBankStatementImporter implements BankStatementImporter
{
    public function parse(string $contents): ParsedBankStatement
    {
        if ($contents === '' || str_contains($contents, "\0")) {
            throw new BankImportInvalidFile('O arquivo OFX está vazio ou contém bytes inválidos.');
        }

        if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)|\b(?:SYSTEM|PUBLIC)\s+["\']/i', $contents)) {
            throw new BankImportInvalidFile('Declarações XML externas ou entidades não são permitidas em OFX.');
        }

        $contents = $this->utf8($contents);
        $bodyOffset = stripos($contents, '<OFX');
        if ($bodyOffset === false) {
            throw new BankImportInvalidFile('O conteúdo não possui a estrutura OFX esperada.');
        }

        $body = substr($contents, $bodyOffset);
        if (! preg_match('/<OFX\b/i', $body) || ! preg_match('/<BANKTRANLIST\b/i', $body)) {
            throw new BankImportInvalidFile('O arquivo não contém um extrato bancário OFX suportado.');
        }

        preg_match_all('/<STMTTRN\b[^>]*>(.*?)<\/STMTTRN\s*>/is', $body, $matches);
        if (($matches[1] ?? []) === []) {
            throw new BankImportInvalidFile('O extrato OFX não contém transações estruturadas.');
        }

        $currency = strtoupper($this->tag($body, 'CURDEF') ?? 'BRL');
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new BankImportInvalidFile('O código de moeda do extrato OFX é inválido.');
        }

        $items = [];
        foreach ($matches[1] as $index => $block) {
            $items[] = $this->parseItem((string) $block, $index + 1, $currency);
        }

        return new ParsedBankStatement(
            items: $items,
            accountMetadata: array_filter([
                'bank_id' => $this->tag($body, 'BANKID'),
                'account_id_bank' => $this->tag($body, 'ACCTID'),
                'account_type' => $this->tag($body, 'ACCTTYPE'),
                'currency' => $currency,
                'ledger_balance' => $this->signedMoney($this->tag($body, 'BALAMT')),
            ], static fn (mixed $value): bool => $value !== null),
            periodStart: $this->ofxDate($this->tag($body, 'DTSTART')),
            periodEnd: $this->ofxDate($this->tag($body, 'DTEND')),
        );
    }

    private function parseItem(string $block, int $position, string $currency): ParsedBankStatementItem
    {
        $rawHash = hash('sha256', $block);
        $externalId = $this->tag($block, 'FITID');
        $type = $this->tag($block, 'TRNTYPE');
        $metadata = array_filter(['ofx_type' => $type], static fn (mixed $value): bool => $value !== null);

        if ($externalId === null || mb_strlen($externalId) > 128) {
            return $this->rejected(
                $position,
                $rawHash,
                $externalId,
                'BANK_TRANSACTION_ID_REQUIRED',
                'A linha OFX não possui FITID forte e não foi descartada silenciosamente.',
                $metadata,
            );
        }

        $amountRaw = $this->tag($block, 'TRNAMT');
        try {
            $signedCents = $amountRaw === null ? 0 : Money::toCents($amountRaw);
        } catch (Throwable) {
            $signedCents = 0;
        }
        if ($signedCents === 0) {
            return $this->rejected(
                $position,
                $rawHash,
                $externalId,
                'BANK_TRANSACTION_INVALID_AMOUNT',
                'A linha OFX possui valor ausente, zero ou inválido.',
                $metadata,
            );
        }

        $dateRaw = $this->tag($block, 'DTPOSTED');
        $transactionDate = $this->ofxDate($dateRaw);
        if ($transactionDate === null) {
            return $this->rejected(
                $position,
                $rawHash,
                $externalId,
                'BANK_TRANSACTION_INVALID_DATE',
                'A linha OFX não possui uma data bancária válida.',
                $metadata,
            );
        }

        $name = $this->tag($block, 'NAME');
        $memo = $this->tag($block, 'MEMO');
        $description = implode(' | ', array_values(array_unique(array_filter([$name, $memo]))));
        if ($description === '') {
            return $this->rejected(
                $position,
                $rawHash,
                $externalId,
                'BANK_TRANSACTION_DESCRIPTION_REQUIRED',
                'A linha OFX não possui descrição bancária.',
                $metadata,
            );
        }

        $transaction = new BankTransactionData(
            accountId: 0,
            sourceSystemId: 0,
            importBatchId: 0,
            externalId: $externalId,
            direction: $signedCents > 0 ? BankTransactionDirection::Credit : BankTransactionDirection::Debit,
            amount: Money::fromCents(abs($signedCents)),
            currency: $currency,
            transactionDate: $transactionDate,
            descriptionOriginal: $description,
            postedAt: $this->ofxDateTime($dateRaw),
            documentNumber: $this->tag($block, 'CHECKNUM'),
            bankReference: $this->tag($block, 'REFNUM'),
            endToEndId: $this->tag($block, 'ENDTOENDID'),
            counterpartyName: $name,
            rawHash: $rawHash,
        );

        return new ParsedBankStatementItem(
            $position,
            $rawHash,
            $externalId,
            $transaction,
            metadata: $metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function rejected(
        int $position,
        string $rawHash,
        ?string $externalId,
        string $code,
        string $message,
        array $metadata,
    ): ParsedBankStatementItem {
        return new ParsedBankStatementItem(
            $position,
            $rawHash,
            $externalId,
            null,
            $code,
            $message,
            $metadata,
        );
    }

    private function tag(string $content, string $tag): ?string
    {
        $quoted = preg_quote($tag, '/');
        if (! preg_match('/<'.$quoted.'\b[^>]*>\s*([^<\r\n]+)/i', $content, $match)) {
            return null;
        }

        $value = trim(html_entity_decode($match[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));

        return $value === '' ? null : $value;
    }

    private function ofxDate(?string $value): ?string
    {
        if ($value === null || ! preg_match('/^(\d{4})(\d{2})(\d{2})/', $value, $match)) {
            return null;
        }

        $date = "{$match[1]}-{$match[2]}-{$match[3]}";
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);

            return $parsed && $parsed->format('Y-m-d') === $date ? $date : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function ofxDateTime(?string $value): ?string
    {
        if ($value === null || ! preg_match(
            '/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})(?:\.\d+)?\[([+-]?\d+)(?::[^\]]+)?\]/',
            $value,
            $match,
        )) {
            return null;
        }

        $offset = (int) $match[7];
        if ($offset < -23 || $offset > 23) {
            return null;
        }

        $sign = $offset < 0 ? '-' : '+';

        return sprintf(
            '%s-%s-%sT%s:%s:%s%s%02d:00',
            $match[1],
            $match[2],
            $match[3],
            $match[4],
            $match[5],
            $match[6],
            $sign,
            abs($offset),
        );
    }

    private function signedMoney(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return Money::fromCents(Money::toCents($value));
        } catch (Throwable) {
            return null;
        }
    }

    private function utf8(string $contents): string
    {
        if (mb_check_encoding($contents, 'UTF-8')) {
            return $contents;
        }

        return mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
    }
}
