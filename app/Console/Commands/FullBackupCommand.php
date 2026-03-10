<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Process\Process;

class FullBackupCommand extends Command
{
    protected $signature = 'backup:full
                            {--dir= : Carpeta donde guardar el backup (por defecto: ../backups)}
                            {--no-tar : Solo exportar BD, no empaquetar el proyecto}';

    protected $description = 'Backup completo: base de datos + proyecto (incl. .env)';

    public function handle(): int
    {
        $baseDir = $this->option('dir') ?? realpath(base_path('..')) . '/backups';
        $timestamp = now()->format('Y-m-d_H-i-s');
        $backupName = "mayoreo-cloud-backup-{$timestamp}";
        $backupPath = $baseDir . '/' . $backupName;

        if (! is_dir($baseDir)) {
            if (! @mkdir($baseDir, 0755, true)) {
                $this->error("No se pudo crear la carpeta: {$baseDir}");
                return self::FAILURE;
            }
        }

        $this->info("Backup en: {$backupPath}");
        @mkdir($backupPath, 0755, true);

        // 1. Exportar base de datos
        $dbFile = $backupPath . '/database.sql';
        if ($this->dumpDatabase($dbFile) !== 0) {
            $this->error('Error al exportar la base de datos.');
            return self::FAILURE;
        }
        $this->info('Base de datos exportada: database.sql');

        // 2. Copiar proyecto (incl. .env) excluyendo vendor, node_modules, y cache
        if (! $this->option('no-tar')) {
            $tarFile = $baseDir . '/' . $backupName . '.tar.gz';
            $projectRoot = base_path();
            $baseName = basename($projectRoot);
            $excludes = [
                '--exclude=' . $baseName . '/vendor',
                '--exclude=' . $baseName . '/node_modules',
                '--exclude=' . $baseName . '/python/venv',
                '--exclude=' . $baseName . '/storage/logs/*.log',
                '--exclude=' . $baseName . '/storage/framework/cache/data/*',
                '--exclude=' . $baseName . '/storage/framework/views/*.php',
                '--exclude=' . $baseName . '/.git',
            ];
            // tar: exclude debe ir antes de -C y de los archivos
            $cmd = array_merge(
                ['tar', '-czf', $tarFile],
                $excludes,
                ['-C', dirname($projectRoot), $baseName]
            );
            $process = new Process($cmd);
            $process->setTimeout(300);
            $process->run();
            if (! $process->isSuccessful()) {
                $this->error('Error al crear el archivo tar: ' . $process->getErrorOutput());
                return self::FAILURE;
            }
            $this->info('Proyecto empaquetado: ' . basename($tarFile));

            // Añadir el .sql al tar (el dump está en backupPath, moverlo o añadirlo)
            $process2 = new Process([
                'tar',
                '-C',
                $backupPath,
                '-czf',
                $baseDir . '/' . $backupName . '-solo-bd.tar.gz',
                'database.sql',
            ]);
            $process2->run();
        }

        $this->newLine();
        $this->info('Backup completado.');
        $this->line("  Base de datos: {$backupPath}/database.sql");
        if (! $this->option('no-tar')) {
            $this->line("  Proyecto: {$baseDir}/{$backupName}.tar.gz");
        }

        return self::SUCCESS;
    }

    private function dumpDatabase(string $outputFile): int
    {
        $conn = Config::get('database.connections.mysql');
        $host = $conn['host'] ?? '127.0.0.1';
        $port = $conn['port'] ?? 3306;
        $database = $conn['database'];
        $username = $conn['username'];
        $password = $conn['password'];

        $env = array_merge($_ENV, [
            'MYSQL_PWD' => $password,
        ]);
        $process = new Process(
            [
                'mysqldump',
                '-h', $host,
                '-P', (string) $port,
                '-u', $username,
                '--single-transaction',
                '--quick',
                '--lock-tables=false',
                $database,
            ],
            null,
            $env,
            null,
            120
        );
        $process->run();
        if (! $process->isSuccessful()) {
            $this->error($process->getErrorOutput());
            return 1;
        }
        file_put_contents($outputFile, $process->getOutput());
        return 0;
    }
}
