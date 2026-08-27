<?php

namespace Tests\Unit;

use App\Application\Financial\InstallmentScheduleService;
use App\Domain\Financial\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InstallmentScheduleServiceTest extends TestCase
{
    #[DataProvider('installmentCounts')]
    public function test_it_generates_exact_total_for_one_two_and_three_installments(int $count): void
    {
        $schedule = (new InstallmentScheduleService)->generate('100.00', $count, '2026-01-15');

        self::assertCount($count, $schedule);
        self::assertSame(10000, array_sum(array_map(
            fn (array $installment): int => Money::toCents($installment['amount']),
            $schedule,
        )));
    }

    public function test_rounding_difference_is_assigned_to_last_installment(): void
    {
        $schedule = (new InstallmentScheduleService)->generate('100.00', 3, '2026-01-15');

        self::assertSame(['33.33', '33.33', '33.34'], array_column($schedule, 'amount'));
    }

    public function test_end_of_month_dates_are_predictable(): void
    {
        $schedule = (new InstallmentScheduleService)->generate('300.00', 3, '2027-01-31');

        self::assertSame(
            ['2027-01-31', '2027-02-28', '2027-03-31'],
            array_column($schedule, 'due_date'),
        );
    }

    public static function installmentCounts(): array
    {
        return [[1], [2], [3]];
    }
}
