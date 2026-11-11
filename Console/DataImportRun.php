<?php

namespace App\GP247\Plugins\DataImport\Console;

use App\GP247\Plugins\DataImport\Application\Handlers;
use App\GP247\Plugins\DataImport\Application\ImportRejected;
use App\GP247\Plugins\DataImport\Application\ImportService;
use App\GP247\Plugins\DataImport\Application\Presets;
use App\GP247\Plugins\DataImport\Application\SliceRunner;
use App\GP247\Plugins\DataImport\Domain\JobPhase;
use App\GP247\Plugins\DataImport\Models\ImportJob;
use Illuminate\Console\Command;

/**
 * Run an import from the command line in one go (a VPS without request time limits): create a
 * run from a file, read and plan it, print the review counts, and write only with --approve.
 * `--job=<id>` resumes or approves an existing run; `--undo=<id>` undoes a written run.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-shared-host-run, US-data-import-upload-source, US-data-import-undo, US-data-import-column-mapping,
 *              US-data-import-order-status-mapping
 */
class DataImportRun extends Command
{
    protected $signature = 'data-import:run
        {--file= : CSV file to import (the CLI operator chooses it; it is copied into the run folder)}
        {--entity= : category | brand | supplier | product | customer | order}
        {--source= : Name of the source system, e.g. woocommerce (key of the id map)}
        {--store= : Target store id (default: the root store)}
        {--mapping= : JSON file mapping each source column to a target field (default: columns named like the fields; added to a preset)}
        {--preset= : Ready-made mapping of another platform: woocommerce | shopify | magento}
        {--statuses= : Orders: JSON file with keys status, shipping_status, payment_status; each maps a source value to a GP247 status id}
        {--encoding= : Character set (default: detected)}
        {--delimiter=, : Column separator: , ; or \t}
        {--language= : Language of unsuffixed text columns}
        {--no-update : Skip rows that match an existing record instead of updating it}
        {--emit-events : Send the shop sync events while writing}
        {--approve : Write the reviewed plan}
        {--skip-pre-change : Write although a pre-change check (backup) asked to stop}
        {--job= : Continue an existing run}
        {--undo= : Undo a written run}';

    protected $description = 'Import catalog, customers or order history from a CSV file (review, then write once; undo possible).';

    /**
     * JSON examples live here, not in $signature: the signature parser splits tokens on
     * braces, so a "{...}" inside an option description becomes a bogus argument and
     * breaks every artisan command at boot.
     *
     * @var string
     */
    protected $help = <<<'HELP'
--mapping file example:
  {"Source column": "target field"}

--statuses file example:
  {"status": {"source value": 1}, "shipping_status": {"source value": 1}, "payment_status": {"source value": 1}}
HELP;

    /**
     * @return int
     */
    public function handle(): int
    {
        $service = new ImportService;
        try {
            if ($this->option('undo')) {
                $job = ImportJob::findOrFail((string) $this->option('undo'));
                $service->undo($job, (bool) $this->option('skip-pre-change'));

                return $this->drive($job->id) ? self::SUCCESS : self::FAILURE;
            }
            $job = $this->option('job') ? ImportJob::findOrFail((string) $this->option('job')) : $this->create($service);
            if (!$this->drive($job->id)) {
                return self::FAILURE;
            }
            $job->refresh();
            if ($job->phase === JobPhase::STAGED) {
                if (!$this->option('statuses')) {
                    $this->statusValues($service, $job);

                    return self::SUCCESS;
                }
                $service->mapStatuses($job, $this->jsonFile((string) $this->option('statuses')));
                if (!$this->drive($job->id)) {
                    return self::FAILURE;
                }
                $job->refresh();
            }
            $this->summary($job);
            if ($job->phase === JobPhase::REVIEWED && $this->option('approve')) {
                $service->approve($job, (string) $job->review_fingerprint, null, (bool) $this->option('skip-pre-change'));
                if (!$this->drive($job->id)) {
                    return self::FAILURE;
                }
                $this->summary($job->refresh());
            } elseif ($job->phase === JobPhase::REVIEWED) {
                $this->line('Run ' . $job->id . ' is reviewed. Write it with: php artisan data-import:run --job=' . $job->id . ' --approve');
            }

            return self::SUCCESS;
        } catch (ImportRejected $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param ImportService $service
     * @return ImportJob
     * @throws ImportRejected
     */
    private function create(ImportService $service): ImportJob
    {
        $delimiter = (string) $this->option('delimiter');
        $job = $service->open(
            (string) ($this->option('store') ?: GP247_STORE_ID_ROOT),
            (string) $this->option('entity'),
            (string) $this->option('source'),
            (string) $this->option('file'),
            null,
            $this->option('encoding') ? (string) $this->option('encoding') : null,
            $delimiter === '\t' ? "\t" : $delimiter,
        );
        try {
            $columns = [];
            $options = ['update_existing' => !$this->option('no-update'), 'emit_events' => (bool) $this->option('emit-events')];
            if ($this->option('preset')) {
                $preset = Presets::apply((string) $this->option('preset'), $job->entity, (array) ($job->mapping['headers'] ?? []));
                $columns = $preset['columns'];
                if ($preset['statuses'] !== []) {
                    $options['statuses'] = $preset['statuses'];
                }
                if ($preset['missing'] !== []) {
                    $this->warn('Columns of the preset not in the file: ' . implode(', ', $preset['missing']));
                }
            }
            if ($this->option('mapping')) {
                $columns = array_merge($columns, $this->jsonFile((string) $this->option('mapping')));
            }
            if ($this->option('statuses')) {
                $options['statuses'] = $this->jsonFile((string) $this->option('statuses'));
            }
            if ($this->option('language')) {
                $options['language'] = (string) $this->option('language');
            }
            $service->configure($job, $columns, $options);
        } catch (\Throwable $e) {
            \App\GP247\Plugins\DataImport\Application\JobFiles::purge($job->id);
            $job->delete();
            throw $e;
        }

        return $job;
    }

    /**
     * Read a JSON object from a file the operator names.
     *
     * @param string $path
     * @return array<string, mixed>
     * @throws ImportRejected
     */
    private function jsonFile(string $path): array
    {
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data)) {
            throw new ImportRejected('error.unknown_field', ['value' => $path]);
        }

        return $data;
    }

    /**
     * Print the status values of a staged order run and how to map them.
     *
     * @param ImportService $service
     * @param ImportJob $job
     * @return void
     */
    private function statusValues(ImportService $service, ImportJob $job): void
    {
        $rows = [];
        foreach ($service->statusValues($job) as $kind => $values) {
            foreach ($values as $value => $orders) {
                $rows[] = [$kind, $value === '' ? '(empty)' : $value, $orders];
            }
        }
        $this->table(['column', 'value', 'orders'], $rows);
        $this->line('Map every value to a GP247 status id in a JSON file, then run: php artisan data-import:run --job=' . $job->id . ' --statuses=<file>');
    }

    /**
     * Run slices until the run stops moving.
     *
     * @param string $jobId
     * @return bool False when the run failed or is blocked.
     */
    private function drive(string $jobId): bool
    {
        $runner = new SliceRunner;
        while (true) {
            $result = $runner->run($jobId, 300.0);
            if (in_array($result['state'], [SliceRunner::IDLE], true)) {
                return true;
            }
            if ($result['state'] === SliceRunner::BLOCKED) {
                $this->error('Blocked: ' . $result['blocked_by'] . ' is running.');

                return false;
            }
            if ($result['state'] === SliceRunner::BUSY) {
                $this->error('Another process is working on this run.');

                return false;
            }
            if ($result['phase'] === JobPhase::STALLED) {
                $this->error('The run stalled. See the log, then retry.');

                return false;
            }
        }
    }

    /**
     * @param ImportJob $job
     * @return void
     */
    private function summary(ImportJob $job): void
    {
        $this->line('Run ' . $job->id . ' (' . $job->entity . ', ' . Handlers::group($job->entity) . ') — ' . $job->phase);
        $rows = [];
        foreach ((array) $job->counts as $action => $n) {
            $rows[] = [$action, $n];
        }
        $this->table(['action', 'rows'], $rows);
    }
}
