<?php

namespace App\Application\Reconciliation;

use App\Models\BankTransaction;
use App\Models\FinancialTitle;
use App\Models\TitleInstallment;

class ReconciliationCandidateScorer
{
    public function __construct(private readonly ReconciliationTextNormalizer $normalizer) {}

    /** @return array{score: int, confidence: string, evidence: array<string, mixed>} */
    public function score(FinancialTitle $title, TitleInstallment $installment, BankTransaction $transaction, bool $amountExact = true): array
    {
        $weights = config('reconciliation_matching.weights');
        $signals = [];
        if ($amountExact) {
            $signals['AMOUNT_EXACT'] = $weights['amount_exact'];
        }

        $document = $this->normalizer->identifier($title->document_number);
        $bankDocument = $this->normalizer->identifier($transaction->document_number);
        $reference = $this->normalizer->identifier(($transaction->bank_reference ?? '').' '.($transaction->description_original ?? ''));
        if ($document !== '' && $bankDocument !== '' && hash_equals($document, $bankDocument)) {
            $signals['BUSINESS_DOCUMENT_EXACT'] = $weights['business_document_exact'];
        } elseif ($document !== '' && strlen($document) >= 4 && str_contains($reference, $document)) {
            $signals['DOCUMENT_IN_REFERENCE'] = $weights['document_in_reference'];
        }

        $partyDocument = $this->normalizer->document($title->party_name);
        $counterpartyDocument = $this->normalizer->document($transaction->counterparty_document);
        if ($partyDocument !== '' && $counterpartyDocument !== '' && hash_equals($partyDocument, $counterpartyDocument)) {
            $signals['COUNTERPARTY_DOCUMENT_EXACT'] = $weights['counterparty_document_exact'];
        }

        $party = $this->normalizer->text($title->party_name);
        $counterparty = $this->normalizer->text($transaction->counterparty_name);
        if ($party !== '' && $counterparty !== '' && $party === $counterparty) {
            $signals['COUNTERPARTY_NAME_EXACT'] = $weights['counterparty_name_exact'];
        } else {
            $left = $this->normalizer->tokens($party);
            $right = $this->normalizer->tokens($counterparty);
            if ($left !== [] && $right !== [] && count(array_intersect($left, $right)) >= max(1, min(count($left), count($right)) / 2)) {
                $signals['COUNTERPARTY_NAME_OVERLAP'] = $weights['counterparty_name_overlap'];
            }
        }

        $days = abs($installment->due_date->diffInDays($transaction->transaction_date));
        if ($days === 0) {
            $signals['DATE_SAME'] = $weights['date_same'];
        } elseif ($days <= 3) {
            $signals['DATE_NEAR'] = $weights['date_near'];
        } elseif ($days <= (int) config('reconciliation_matching.date_window_days')) {
            $signals['DATE_WINDOW'] = $weights['date_window'];
        }

        $score = min(100, array_sum($signals));
        $high = (int) config('reconciliation_matching.confidence.high');
        $medium = (int) config('reconciliation_matching.confidence.medium');
        $confidence = $score >= $high ? 'HIGH' : ($score >= $medium ? 'MEDIUM' : 'LOW');

        return [
            'score' => $score,
            'confidence' => $confidence,
            'evidence' => [
                'signals' => array_map(fn (int $impact, string $code): array => ['code' => $code, 'impact' => $impact], $signals, array_keys($signals)),
                'date_distance_days' => $days,
                'explanation' => 'Pontuação determinística calculada apenas com sinais normalizados; dados sensíveis não são persistidos.',
            ],
        ];
    }

    public function hasStrongIdentifier(FinancialTitle $title, BankTransaction $transaction): bool
    {
        $document = $this->normalizer->identifier($title->document_number);
        if ($document === '' || strlen($document) < 4) {
            return false;
        }

        return $document === $this->normalizer->identifier($transaction->document_number)
            || str_contains($this->normalizer->identifier(($transaction->bank_reference ?? '').' '.($transaction->description_original ?? '')), $document);
    }
}
