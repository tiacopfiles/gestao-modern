<?php

declare(strict_types=1);

use Acop\Homologation\HomologationGuard;
use App\Application\Reconciliation\ManualReconciliationService;
use App\Application\Reconciliation\ReconciliationCandidateService;
use App\Application\Reconciliation\ReconciliationClosureService;
use App\Application\Reconciliation\ReconciliationReopeningService;
use App\Domain\Reconciliation\Exceptions\ReconciliationRuleViolation;
use App\Domain\Reconciliation\ReconciliationTitleAllocationData;
use App\Domain\Reconciliation\ReconciliationTransactionAllocationData;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
// Precisa ser o UploadedFile do Illuminate, não o do Symfony:
// `Request::convertUploadedFiles()` chama `UploadedFile::createFromBase()`, que
// reconstrói qualquer instância Symfony com `$test = false` e descarta a flag de
// teste. O arquivo sintético então falha em `is_uploaded_file()` e a requisição
// morre em 422 `validation.uploaded`. Uma instância Illuminate é devolvida
// como está, preservando `test: true`.
use Illuminate\Http\UploadedFile;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/HomologationGuard.php';

try {
    HomologationGuard::assertSafe();
    $inputFile = $argv[1] ?? '';
    $input = json_decode((string) file_get_contents($inputFile), true, flags: JSON_THROW_ON_ERROR);
    $startAt = (float) ($input['start_at'] ?? microtime(true));
    while (microtime(true) < $startAt) {
        usleep(1000);
    }
    $actualStart = microtime(true);

    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    config([
        'reconciliation.v2_enabled' => true,
        'reconciliation.matching_enabled' => true,
        // Fase 6: sem isto, close()/reopen() param em assertEnabled() e o cenário
        // de concorrência mediria a flag, não o lock.
        'reconciliation.closing_enabled' => true,
    ]);

    $mode = (string) ($input['mode'] ?? '');
    if ($mode === 'http_json' || $mode === 'http_ofx') {
        $server = ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$input['token']];
        $files = [];
        $content = null;
        $parameters = [];
        if ($mode === 'http_json') {
            $server['CONTENT_TYPE'] = 'application/json';
            $content = json_encode($input['payload'], JSON_THROW_ON_ERROR);
        } else {
            $parameters = ['account_id' => $input['account_id']];
            $files = ['file' => new UploadedFile($input['file'], basename($input['file']), 'application/x-ofx', null, true)];
        }
        $request = Request::create($input['uri'], $input['method'] ?? 'POST', $parameters, [], $files, $server, $content);
        $request->headers->set('Idempotency-Key', $input['idempotency_key']);
        $request->headers->set('X-Correlation-ID', $input['correlation_id']);
        /** @var Kernel $kernel */
        $kernel = $app->make(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        $result = [
            'outcome' => 'HTTP', 'status' => $response->getStatusCode(),
            'replayed' => $response->headers->get('Idempotency-Replayed'),
            'body' => json_decode((string) $response->getContent(), true),
        ];
    } elseif ($mode === 'manual_confirm') {
        $match = $app->make(ManualReconciliationService::class)->confirm(
            (int) $input['session_id'],
            [new ReconciliationTitleAllocationData((int) $input['title_id'], (int) $input['installment_id'], (string) $input['amount'])],
            [new ReconciliationTransactionAllocationData((int) $input['transaction_id'], (string) $input['amount'])],
            (int) $input['actor_id'], (string) $input['correlation_id'],
        );
        $result = ['outcome' => 'MATCH', 'match_id' => $match->id];
    } elseif ($mode === 'candidate_accept') {
        $match = $app->make(ReconciliationCandidateService::class)->accept(
            (int) $input['session_id'], (int) $input['candidate_id'],
            (int) $input['actor_id'], (string) $input['correlation_id'],
        );
        $result = ['outcome' => 'ACCEPTED', 'match_id' => $match->id];
    } elseif ($mode === 'close') {
        $closure = $app->make(ReconciliationClosureService::class)->close(
            (int) $input['session_id'], (int) $input['actor_id'], (string) $input['correlation_id'],
        );
        $result = ['outcome' => 'CLOSED', 'closure_id' => $closure->id, 'sequence_number' => (int) $closure->sequence_number];
    } elseif ($mode === 'reopen') {
        $closure = $app->make(ReconciliationReopeningService::class)->reopen(
            (int) $input['session_id'], (int) $input['closure_id'], (string) $input['reason'],
            (int) $input['actor_id'], (string) $input['correlation_id'],
        );
        $result = ['outcome' => 'REOPENED', 'closure_id' => $closure->id];
    } else {
        throw new RuntimeException('Modo de worker inválido.');
    }

    echo json_encode($result + ['started_at' => $actualStart, 'finished_at' => microtime(true)], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (ReconciliationRuleViolation $exception) {
    echo json_encode(['outcome' => 'RULE', 'rule' => $exception->rule, 'started_at' => $actualStart ?? microtime(true), 'finished_at' => microtime(true)], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode(['outcome' => 'ERROR', 'class' => $exception::class, 'message' => $exception->getMessage(), 'started_at' => $actualStart ?? microtime(true), 'finished_at' => microtime(true)], JSON_THROW_ON_ERROR).PHP_EOL;
}
