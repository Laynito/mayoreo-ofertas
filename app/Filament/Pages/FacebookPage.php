<?php

namespace App\Filament\Pages;

use App\Services\FacebookService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

class FacebookPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationLabel = 'Facebook';

    protected static ?string $title = 'Fan Page y publicaciones';

    protected static ?string $navigationGroup = 'Marketing';

    protected static string $view = 'filament.pages.facebook-page';

    protected static ?int $navigationSort = 10;

    public ?array $pageInfo = null;

    public ?array $insights = null;

    public ?array $posts = null;

    public ?string $insightsError = null;

    public ?string $postsError = null;

    public function mount(FacebookService $facebook): void
    {
        if (! $facebook->hasCredentials()) {
            $this->pageInfo = ['error' => 'Faltan FB_PAGE_ID o FB_PAGE_ACCESS_TOKEN en .env o en Marketplace → Facebook.'];

            return;
        }

        $this->pageInfo = $facebook->getPageInfo();
        if (isset($this->pageInfo['error'])) {
            return;
        }

        $since = now()->subDays(7)->format('Y-m-d');
        $until = now()->format('Y-m-d');
        $insightsResult = $facebook->getInsights(
            ['page_impressions', 'page_engaged_users', 'page_fans'],
            'day',
            $since,
            $until
        );
        if (isset($insightsResult['error'])) {
            $this->insightsError = $insightsResult['error']['message'] ?? 'Error al cargar insights.';
        } else {
            $this->insights = $insightsResult['data'] ?? [];
        }

        $postsResult = $facebook->getPublishedPosts(15);
        if (isset($postsResult['error'])) {
            $this->postsError = $postsResult['error']['message'] ?? 'Error al cargar publicaciones.';
        } else {
            $this->posts = $postsResult['data'] ?? [];
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Actualizar')
                ->icon('heroicon-o-arrow-path')
                ->action(fn () => $this->mount(app(FacebookService::class))),
            Action::make('publish')
                ->label('Publicar oferta ahora')
                ->icon('heroicon-o-plus')
                ->color('success')
                ->action(function (): void {
                    Artisan::call('facebook:publish');
                    $output = trim(Artisan::output());
                    Notification::make()
                        ->title('Publicador ejecutado')
                        ->body(strlen($output) > 500 ? substr($output, 0, 500) . '…' : $output)
                        ->success()
                        ->send();
                    $this->mount(app(FacebookService::class));
                }),
        ];
    }

    public function deletePost(string $postId): void
    {
        $facebook = app(FacebookService::class);
        $result = $facebook->deletePost($postId);
        if ($result['success'] ?? false) {
            Notification::make()->title('Publicación eliminada')->success()->send();
            $this->posts = array_values(array_filter($this->posts ?? [], fn ($p) => ($p['id'] ?? '') !== $postId));
        } else {
            Notification::make()
                ->title('No se pudo eliminar')
                ->body($result['error'] ?? 'Error desconocido')
                ->danger()
                ->send();
        }
    }

    public function formatInsightValue(array $values): string
    {
        if (empty($values)) {
            return '—';
        }
        $total = 0;
        foreach ($values as $v) {
            $total += (int) ($v['value'] ?? 0);
        }

        return number_format($total);
    }

    public function formatPostDate(?string $date): string
    {
        if (! $date) {
            return '—';
        }

        return Carbon::parse($date)->locale('es')->diffForHumans();
    }
}
