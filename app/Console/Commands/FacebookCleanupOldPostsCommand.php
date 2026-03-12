<?php

namespace App\Console\Commands;

use App\Services\FacebookService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class FacebookCleanupOldPostsCommand extends Command
{
    protected $signature = 'facebook:cleanup-old-posts
                            {--days=14 : Eliminar publicaciones más viejas que este número de días sin interacción}
                            {--limit=100 : Máximo de publicaciones a revisar}
                            {--dry-run : Solo listar qué se eliminaría, sin borrar}
                            {--force : No pedir confirmación (para cron)}';

    protected $description = 'Elimina publicaciones viejas de la Fan Page que no tuvieron interacción (reacciones/comentarios). Las que sí tuvieron se mantienen.';

    public function handle(FacebookService $facebook): int
    {
        if (! $facebook->hasCredentials()) {
            $this->error('Faltan credenciales de Facebook (FB_PAGE_ID / FB_PAGE_ACCESS_TOKEN).');

            return self::FAILURE;
        }

        $days = (int) $this->option('days');
        $limit = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');
        $cutoff = Carbon::now()->subDays($days);

        $this->info("Revisando publicaciones de más de {$days} días (antes del " . $cutoff->toDateString() . ")...");

        $result = $facebook->getPublishedPosts($limit, true);

        if (isset($result['error'])) {
            $this->error($result['error']['message'] ?? 'Error al obtener publicaciones.');

            return self::FAILURE;
        }

        $posts = $result['data'] ?? [];
        if (empty($posts)) {
            $this->info('No hay publicaciones en la página.');

            return self::SUCCESS;
        }

        $toDelete = [];
        $toKeep = [];

        foreach ($posts as $post) {
            $id = $post['id'] ?? null;
            $createdTime = $post['created_time'] ?? null;
            if (! $id || ! $createdTime) {
                continue;
            }

            $created = Carbon::parse($createdTime);
            $engagement = FacebookService::getPostEngagementCount($post);

            if ($created->gte($cutoff)) {
                continue;
            }

            if ($engagement > 0) {
                $toKeep[] = ['id' => $id, 'created' => $created->toDateString(), 'engagement' => $engagement];
            } else {
                $toDelete[] = ['id' => $id, 'created' => $created->toDateString(), 'message' => \Illuminate\Support\Str::limit($post['message'] ?? '(sin texto)', 50)];
            }
        }

        if (empty($toDelete)) {
            $this->info('No hay publicaciones viejas sin interacción para eliminar.');
            if (! empty($toKeep)) {
                $this->comment('Se mantienen ' . count($toKeep) . ' publicación(es) con interacción.');
            }

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Fecha', 'Mensaje'],
            array_map(fn ($p) => [$p['id'], $p['created'], $p['message']], $toDelete)
        );
        $this->warn('Publicaciones a eliminar (sin reacciones ni comentarios): ' . count($toDelete));
        if (! empty($toKeep)) {
            $this->info('Se mantienen ' . count($toKeep) . ' publicación(es) con interacción.');
        }

        if ($dryRun) {
            $this->comment('Modo dry-run: no se eliminó nada. Quita --dry-run para ejecutar.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('¿Eliminar estas ' . count($toDelete) . ' publicación(es)?')) {
            return self::SUCCESS;
        }

        $deleted = 0;
        foreach ($toDelete as $p) {
            $resp = $facebook->deletePost($p['id']);
            if ($resp['success'] ?? false) {
                $deleted++;
                $this->line("  Eliminada: {$p['id']}");
            } else {
                $this->warn("  No se pudo eliminar {$p['id']}: " . ($resp['error'] ?? 'error desconocido'));
            }
        }

        $this->info("Listo. Eliminadas {$deleted} publicación(es).");

        return self::SUCCESS;
    }
}
