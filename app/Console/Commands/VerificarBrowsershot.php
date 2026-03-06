<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Browsershot\Browsershot;
use Spatie\Browsershot\Exceptions\CouldNotTakeBrowsershot;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Comprueba si Browsershot (Puppeteer/Chromium) puede tomar capturas en este servidor.
 * Si falla por librerías (libatk, etc.), ejecuta scripts/instalar-dependencias-browsershot.sh en el VPS.
 */
class VerificarBrowsershot extends Command
{
    protected $signature = 'browsershot:verificar';

    protected $description = 'Prueba si Browsershot puede tomar una captura (Chromium y dependencias de sistema)';

    public function handle(): int
    {
        $this->info('Probando Browsershot (captura de página de prueba)...');

        $rutaTemp = storage_path('app/temp/prueba-browsershot-' . uniqid() . '.png');
        $dir = dirname($rutaTemp);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        try {
            $shot = Browsershot::url('https://example.com')
                ->noSandbox()
                ->addChromiumArguments([0 => 'disable-setuid-sandbox'])
                ->timeout(15)
                ->windowSize(400, 300);
            $chromePath = config('browsershot.chrome_path', '');
            if ($chromePath !== '' && is_string($chromePath) && is_executable($chromePath)) {
                $shot->setChromePath($chromePath);
            }
            $shot->save($rutaTemp);

            if (is_file($rutaTemp) && filesize($rutaTemp) > 0) {
                @unlink($rutaTemp);
                $this->info('✓ Browsershot funciona. Las capturas de bajada histórica deberían enviarse correctamente.');
                return self::SUCCESS;
            }

            $this->error('La captura se ejecutó pero el archivo está vacío.');
            return self::FAILURE;
        } catch (ProcessTimedOutException $e) {
            $this->error('Timeout: Chromium tardó demasiado.');
            $this->line($e->getMessage());
            return self::FAILURE;
        } catch (CouldNotTakeBrowsershot $e) {
            $this->error('Browsershot no pudo tomar la captura.');
            $this->line($e->getMessage());
            $this->newLine();
            $this->sugerirSolucion($e->getMessage());
            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Error: ' . $e->getMessage());
            $this->newLine();
            $this->sugerirSolucion($e->getMessage());
            return self::FAILURE;
        }
    }

    private function sugerirSolucion(string $mensaje): void
    {
        if (str_contains($mensaje, 'Could not find Chrome')) {
            $this->warn('Puppeteer no encuentra Chrome. En el servidor:');
            $this->line('  1. Instala Chromium: sudo apt install chromium (o chromium-browser)');
            $this->line('  2. En .env añade: BROWSERSHOT_CHROME_PATH=/usr/bin/chromium');
            $this->line('     (o la ruta que devuelva: which chromium)');
            return;
        }
        if (str_contains($mensaje, 'libatk') || str_contains($mensaje, 'shared libraries')) {
            $this->warn('Faltan dependencias de Chromium. Ejecuta en el VPS:');
            $this->line('  bash ' . base_path('scripts/instalar-dependencias-browsershot.sh'));
        }
    }
}
