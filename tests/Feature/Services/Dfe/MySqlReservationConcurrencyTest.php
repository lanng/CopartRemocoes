<?php

namespace Tests\Feature\Services\Dfe;

use App\Enums\CteDocumentStatusEnum;
use App\Models\Ciot;
use App\Models\CteDocument;
use App\Models\DfeDocumentReservation;
use App\Models\MdfeDocument;
use App\Services\Dfe\ReserveMdfeDocuments;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;
use Throwable;

class MySqlReservationConcurrencyTest extends TestCase
{
    public function test_concurrent_manifest_reservations_allow_only_one_winner_on_mysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('A disputa de locks precisa ser executada com MySQL.');
        }

        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('A disputa entre processos requer a extensão pcntl.');
        }

        $cte = CteDocument::factory()->authorized()->create([
            'workflow_origin' => 'controlled_v1',
        ]);
        $firstCiot = Ciot::factory()->issued()->create();
        $secondCiot = Ciot::factory()->issued()->create();
        $firstMdfe = $this->queuedMdfe($cte, $firstCiot);
        $secondMdfe = $this->queuedMdfe($cte, $secondCiot);
        $directory = sys_get_temp_dir().'/dfe-reservation-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);

        $children = [];
        $lockConnection = null;

        try {
            foreach ([
                ['mdfe_id' => $firstMdfe->id, 'ciot_id' => $firstCiot->id],
                ['mdfe_id' => $secondMdfe->id, 'ciot_id' => $secondCiot->id],
            ] as $index => $resources) {
                $pid = pcntl_fork();

                if ($pid === -1) {
                    $this->fail('Não foi possível iniciar os processos de disputa MySQL.');
                }

                if ($pid === 0) {
                    $this->runReservationWorker($directory, $index, $cte->id, $resources['mdfe_id'], $resources['ciot_id']);
                }

                $children[] = $pid;
            }

            $this->waitForFiles([
                $directory.'/ready-0',
                $directory.'/ready-1',
            ]);

            DB::purge();
            $lockConnection = DB::connection()->getPdo();
            $lockConnection->beginTransaction();
            $lockConnection
                ->prepare('select id from cte_documents where id = ? for update')
                ->execute([$cte->id]);
            file_put_contents($directory.'/start', 'go');
            usleep(250000);
            $lockConnection->commit();

            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status), 'Um processo da disputa terminou inesperadamente.');
                $this->assertSame(0, pcntl_wexitstatus($status));
            }

            $outcomes = [
                $this->readOutcome($directory.'/result-0'),
                $this->readOutcome($directory.'/result-1'),
            ];

            $this->assertCount(1, array_filter($outcomes, fn (array $outcome): bool => $outcome['result'] === 'reserved'));
            $conflicts = array_values(array_filter($outcomes, fn (array $outcome): bool => $outcome['result'] === 'conflict'));
            $this->assertCount(1, $conflicts, json_encode($outcomes, JSON_UNESCAPED_UNICODE));
            $this->assertStringContainsString('reservado', mb_strtolower((string) $conflicts[0]['message']));
            $this->assertSame(1, DfeDocumentReservation::query()
                ->where('resource_type', 'cte')
                ->where('resource_id', $cte->id)
                ->count());
            $this->assertSame(1, DfeDocumentReservation::query()
                ->where('resource_type', 'ciot')
                ->count());
        } finally {
            if ($lockConnection instanceof PDO && $lockConnection->inTransaction()) {
                $lockConnection->rollBack();
            }

            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status, WNOHANG);

                if (pcntl_wifexited($status) || pcntl_wifsignaled($status)) {
                    continue;
                }

                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }

            DfeDocumentReservation::query()
                ->whereIn('mdfe_document_id', [$firstMdfe->id, $secondMdfe->id])
                ->delete();
            $firstMdfe->cteDocuments()->detach();
            $secondMdfe->cteDocuments()->detach();
            $firstMdfe->delete();
            $secondMdfe->delete();
            $firstCiot->delete();
            $secondCiot->delete();
            $cte->delete();
            $this->removeDirectory($directory);
        }
    }

    private function queuedMdfe(CteDocument $cte, Ciot $ciot): MdfeDocument
    {
        $mdfe = MdfeDocument::factory()->create([
            'ciot_id' => $ciot->id,
            'status' => CteDocumentStatusEnum::QUEUED,
        ]);
        $mdfe->cteDocuments()->attach($cte->id, ['position' => 1]);

        return $mdfe;
    }

    private function runReservationWorker(string $directory, int $worker, int $cteId, int $mdfeId, int $ciotId): never
    {
        try {
            DB::purge();
            DB::connection()->getPdo();
            file_put_contents($directory.'/ready-'.$worker, 'ready');
            $this->waitForFiles([$directory.'/start']);

            $cte = CteDocument::query()->findOrFail($cteId);
            $mdfe = MdfeDocument::query()->findOrFail($mdfeId);
            $ciot = Ciot::query()->findOrFail($ciotId);
            app(ReserveMdfeDocuments::class)->reserve($mdfe, collect([$cte]), $ciot);
            $result = ['result' => 'reserved', 'message' => null];
        } catch (Throwable $exception) {
            $result = [
                'result' => $exception instanceof \DomainException ? 'conflict' : 'error',
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ];
        }

        file_put_contents($directory.'/result-'.$worker, json_encode($result, JSON_UNESCAPED_UNICODE));
        exit(0);
    }

    /** @param list<string> $paths */
    private function waitForFiles(array $paths): void
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            if (collect($paths)->every(fn (string $path): bool => is_file($path))) {
                return;
            }

            usleep(10000);
        }

        throw new \RuntimeException('Tempo esgotado aguardando a barreira dos processos MySQL.');
    }

    /** @return array{result: string, message: ?string, exception?: string} */
    private function readOutcome(string $path): array
    {
        $contents = file_get_contents($path);
        $outcome = is_string($contents) ? json_decode($contents, true) : null;

        if (! is_array($outcome)) {
            throw new \RuntimeException('Um processo MySQL não registrou o resultado da disputa.');
        }

        return $outcome;
    }

    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }

        rmdir($directory);
    }
}
