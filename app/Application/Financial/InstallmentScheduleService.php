<?php

namespace App\Application\Financial;

use App\Domain\Financial\Money;
use Carbon\CarbonImmutable;
use DomainException;

class InstallmentScheduleService
{
    /**
     * @return list<array{installment_number: int, due_date: string, amount: string, status: string}>
     */
    public function generate(int|string $totalAmount, int $count, string $firstDueDate): array
    {
        if ($count < 1 || $count > 999) {
            throw new DomainException('A quantidade de parcelas deve estar entre 1 e 999.');
        }

        $totalCents = Money::toCents($totalAmount);
        if ($totalCents <= 0) {
            throw new DomainException('O valor total deve ser maior que zero.');
        }

        $firstDue = CarbonImmutable::parse($firstDueDate)->startOfDay();
        $anchorDay = $firstDue->day;
        $endOfMonth = $anchorDay === $firstDue->daysInMonth;
        $baseCents = intdiv($totalCents, $count);
        $remainder = $totalCents - ($baseCents * $count);
        $schedule = [];

        for ($index = 0; $index < $count; $index++) {
            $month = $firstDue->startOfMonth()->addMonths($index);
            $day = $endOfMonth ? $month->daysInMonth : min($anchorDay, $month->daysInMonth);
            $amountCents = $baseCents + ($index === $count - 1 ? $remainder : 0);

            $schedule[] = [
                'installment_number' => $index + 1,
                'due_date' => $month->setDay($day)->toDateString(),
                'amount' => Money::fromCents($amountCents),
                'status' => 'OPEN',
            ];
        }

        return $schedule;
    }
}
