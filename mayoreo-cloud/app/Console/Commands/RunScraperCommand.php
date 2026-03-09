<?php

namespace App\Console\Commands;

use App\Models\Producto;
use App\Models\ProductoPrecioHistorial;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class RunScraperCommand extends Command
{
    protected $signature = 'app:run-scraper';

    protected $description = 'Ejecuta el scraper de Python (scraper_ml.py) y luego productos:sync-affiliate --send-telegram';

    private function writeScraperStatus(string $status, ?string $finishedAt = null): void
    {
        $data = [
            'started_at' => $this->scraperStartedAt ?? now()->toIso8601String(),
            'status' => $status,
        ];
        if ($finishedAt !== null) {
            $data['finished_at'] = $finishedAt;
        }
        Storage::put('scraper_status.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private ?string $scraperStartedAt = null;

    public function handle(): int
    {
        $this->scraperStartedAt = now()->toIso8601String();
        $this->writeScraperStatus('running');
        Log::info('Scraper iniciado', ['started_at' => $this->scraperStartedAt]);

        $hoy = Carbon::today()->toDateString();
        $this->info('Guardando snapshot de precios actuales para comparar con ayer...');
        Producto::query()
            ->select('id', 'precio_actual')
            ->get()
            ->each(function (Producto $p) use ($hoy): void {
                ProductoPrecioHistorial::query()->updateOrInsert(
                    ['producto_id' => $p->id, 'fecha' => $hoy],
                    ['precio_actual' => $p->precio_actual]
                );
            });

        $pythonBinary = base_path('python/venv/bin/python');
        $pythonScript = base_path('python/scraper_ml.py');

        if (! is_file($pythonScript)) {
            $this->error('No se encuentra python/scraper_ml.py');
            $this->writeScraperStatus('failed', now()->toIso8601String());
            Log::warning('Scraper fallido: no se encuentra python/scraper_ml.py');
            return self::FAILURE;
        }

        if (! is_file($pythonBinary)) {
            $this->error('No se encuentra python/venv/bin/python. Crea el venv en python/ e instala dependencias.');
            $this->writeScraperStatus('failed', now()->toIso8601String());
            Log::warning('Scraper fallido: no se encuentra python/venv/bin/python');
            return self::FAILURE;
        }

        // -u = salida sin buffer para ver progreso en tiempo real
        $command = [$pythonBinary, '-u', $pythonScript];

        $this->info('Ejecutando scraper de Python en vivo (puede tardar 2-5 min; salida en tiempo real)...');
        $process = Process::path(base_path())
            ->timeout(300)
            ->start($command);

        while ($process->running()) {
            $newOut = $process->latestOutput();
            $newErr = $process->latestErrorOutput();
            if ($newOut !== '') {
                $this->line($newOut);
            }
            if ($newErr !== '') {
                $this->error($newErr);
            }
            usleep(200000); // 200 ms
        }
        $this->line($process->latestOutput());
        if ($process->latestErrorOutput() !== '') {
            $this->error($process->latestErrorOutput());
        }
        $result = $process->wait();

        if (! $result->successful()) {
            $this->error('El scraper finalizó con código: ' . $result->exitCode());
            $this->writeScraperStatus('failed', now()->toIso8601String());
            Log::warning('Scraper fallido: proceso Python salió con código ' . $result->exitCode());
            return self::FAILURE;
        }

        $this->info('Scraper finalizado. Sincronizando afiliados y encolando Telegram...');
        $exitCode = Artisan::call('productos:sync-affiliate', ['--send-telegram' => true]);
        $this->line(Artisan::output());
        if ($exitCode !== 0) {
            $this->writeScraperStatus('failed', now()->toIso8601String());
            Log::warning('Scraper fallido: productos:sync-affiliate devolvió código ' . $exitCode);
            return self::FAILURE;
        }

        $this->info('Procesando cola (envío a Telegram)...');
        Artisan::call('queue:work', ['--stop-when-empty' => true]);
        $this->line(Artisan::output());

        $this->writeScraperStatus('success', now()->toIso8601String());
        Log::info('Scraper finalizado correctamente', ['finished_at' => now()->toIso8601String()]);
        return self::SUCCESS;
    }
}
