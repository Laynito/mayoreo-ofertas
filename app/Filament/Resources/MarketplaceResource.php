<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MarketplaceResource\Pages;
use App\Models\Marketplace;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class MarketplaceResource extends Resource
{
    protected static ?string $model = Marketplace::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Marketplace';

    protected static ?string $modelLabel = 'Marketplace';

    protected static ?string $pluralModelLabel = 'Marketplaces';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Datos generales')
                    ->description('Nombre, identificador y estado del marketplace.')
                    ->schema([
                        Forms\Components\TextInput::make('nombre')
                            ->label('Nombre')
                            ->placeholder('Ej: Mercado Libre México')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (string $operation, $state, Forms\Set $set) => $operation === 'create' ? $set('slug', Str::slug($state)) : null),
                        Forms\Components\TextInput::make('slug')
                            ->label('Slug')
                            ->placeholder('Ej: mercado_libre, walmart')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255)
                            ->rules(['alpha_dash']),
                        Forms\Components\Toggle::make('es_activo')
                            ->label('Activo')
                            ->default(true)
                            ->helperText('Si está desactivado, el scraper no ejecutará para este marketplace.'),
                        Forms\Components\Textarea::make('verification_code')
                            ->label('Código de verificación meta')
                            ->placeholder('<meta name="..." content="...">')
                            ->helperText('Pega aquí la etiqueta meta de verificación que te proporcione la red de afiliados (ej. Admitad). Puedes guardarla para usarla cuando vuelvas a activar la inyección en el layout.')
                            ->nullable()
                            ->columnSpanFull(),
                    ])
                    ->columns(1),

                Forms\Components\Section::make('Datos de la app de Facebook')
                    ->description('Solo para el marketplace Facebook. Page ID y Token para publicar ofertas en la Fan Page.')
                    ->schema([
                        Forms\Components\TextInput::make('configuracion.fb_page_id')
                            ->label('Page ID de Facebook')
                            ->placeholder('Ej: 123456789012345')
                            ->helperText('ID numérico de tu página. Lo ves en Meta Business Suite o en la URL de la página.'),
                        Forms\Components\TextInput::make('configuracion.fb_page_access_token')
                            ->label('Token de acceso de página')
                            ->password()
                            ->revealable()
                            ->placeholder('Token de larga duración')
                            ->helperText('Page Access Token de larga duración. También puedes ponerlo en .env como FB_PAGE_ACCESS_TOKEN.'),
                    ])
                    ->columns(1)
                    ->visible(fn ($get, $record) => $record?->slug === 'facebook')
                    ->collapsible(),

                Forms\Components\Section::make('TikTok (Development / perfil)')
                    ->description('Bio, descripción de la app (120 caracteres), URLs legales y credenciales para TikTok for Developers.')
                    ->schema([
                        Forms\Components\TextInput::make('configuracion.bio_description')
                            ->label('Descripción de perfil (bio)')
                            ->placeholder('Ej: Ofertas del día en ML, Coppel y más. Precios bajos. Link en bio 👇')
                            ->maxLength(80)
                            ->live(onBlur: true)
                            ->helperText('Máximo 80 caracteres. Para la bio del perfil de TikTok.')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('configuracion.app_description')
                            ->label('Description (app, 120 caracteres)')
                            ->placeholder('A website that shows daily deals from Mercado Libre, Coppel and more. Use the link in bio to shop.')
                            ->maxLength(120)
                            ->live(onBlur: true)
                            ->helperText('Obligatorio en TikTok for Developers. Se muestra a los usuarios. Máx. 120 caracteres.')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('configuracion.terms_of_service_url')
                            ->label('Terms of Service URL')
                            ->placeholder('https://mayoreo.cloud/terminos')
                            ->url()
                            ->helperText('Obligatorio. Enlace a los términos de uso de tu sitio.'),
                        Forms\Components\TextInput::make('configuracion.privacy_policy_url')
                            ->label('Privacy Policy URL')
                            ->placeholder('https://mayoreo.cloud/aviso-de-privacidad')
                            ->url()
                            ->helperText('Obligatorio. Enlace al aviso de privacidad.'),
                        Forms\Components\TextInput::make('configuracion.client_key')
                            ->label('Client Key')
                            ->placeholder('Client key de tu app en developers.tiktok.com')
                            ->helperText('Opcional. Lo obtienes en Manage apps → tu app → App key.'),
                        Forms\Components\TextInput::make('configuracion.client_secret')
                            ->label('Client Secret')
                            ->password()
                            ->revealable()
                            ->placeholder('Client secret de la app')
                            ->helperText('Opcional. Para Share Kit, Content Posting API, etc. También puedes usar .env TIKTOK_CLIENT_SECRET.'),
                    ])
                    ->columns(1)
                    ->visible(fn ($get, $record) => $record?->slug === 'tiktok')
                    ->collapsible(),

                Forms\Components\Section::make('Configuración de afiliados')
                    ->description('IDs y URL base para enlaces de afiliado y búsqueda de ofertas.')
                    ->schema([
                        Forms\Components\Toggle::make('configuracion.es_afiliados')
                            ->label('Es programa de afiliados')
                            ->default(false)
                            ->helperText('Marcar si este marketplace tiene programa de afiliados. Tendrá prioridad al enviar ofertas a Telegram (primero ML, luego Coppel, luego otros). Los no marcados se usan para generar contenido sin afiliado.')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('affiliate_id')
                            ->label('ID de afiliado (affid)')
                            ->placeholder('Ej: 187001804'),
                        Forms\Components\TextInput::make('app_id')
                            ->label('App ID (matt_tool)')
                            ->placeholder('Ej: 6883208304957920'),
                        Forms\Components\TextInput::make('url_busqueda')
                            ->label('URL base de búsqueda / ofertas')
                            ->placeholder('Ej: https://www.mercadolibre.com.mx/ofertas')
                            ->url()
                            ->rules(['nullable', 'url', 'starts_with:https://'])
                            ->helperText('Solo URLs válidas que comiencen con https://')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Configuración de Sesión')
                    ->description('Credenciales y cookies. El script rellena cookies_json al guardar la sesión.')
                    ->schema([
                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->placeholder('Ej: mi@email.com')
                            ->nullable(),
                        Forms\Components\TextInput::make('password')
                            ->label('Contraseña')
                            ->password()
                            ->revealable()
                            ->placeholder('••••••••')
                            ->nullable(),
                        Forms\Components\Textarea::make('cookies_json')
                            ->label('Cookies (JSON)')
                            ->placeholder('Lo rellena el script automáticamente.')
                            ->disabled()
                            ->dehydrated(true)
                            ->helperText('Solo lectura: lo actualiza el script al guardar la sesión.')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('affiliate_user')
                            ->label('Email / Usuario (legacy)')
                            ->placeholder('Ej: mi@email.com')
                            ->nullable()
                            ->visible(fn ($record) => $record?->affiliate_user !== null),
                        Forms\Components\TextInput::make('affiliate_password')
                            ->label('Contraseña (legacy)')
                            ->password()
                            ->revealable()
                            ->nullable()
                            ->visible(fn ($record) => $record?->affiliate_password !== null),
                        Forms\Components\Textarea::make('session_data')
                            ->label('Session Data (legacy)')
                            ->disabled()
                            ->dehydrated(true)
                            ->visible(fn ($record) => $record?->session_data !== null)
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->collapsible(),

                Forms\Components\Section::make('URLs de secciones (ofertas)')
                    ->description('Varias URLs para scrapear, ej. Relámpago, Liquidación, etc. Si hay al menos una, el scraper usará estas en lugar de la URL única de arriba.')
                    ->schema([
                        Forms\Components\Textarea::make('urls_secciones')
                            ->label('URLs (una por línea)')
                            ->placeholder("https://www.mercadolibre.com.mx/ofertas\nhttps://www.walmart.com.mx/shop/ofertas-flash-walmart")
                            ->rows(6)
                            ->helperText('Escribe una URL por línea. Todas deben comenzar con https://')
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),

                Forms\Components\Section::make('Configuración adicional (JSON)')
                    ->description('Parámetros extra como matt_word u otros específicos del marketplace.')
                    ->schema([
                        Forms\Components\KeyValue::make('configuracion')
                            ->label('Configuración')
                            ->keyLabel('Clave')
                            ->valueLabel('Valor')
                            ->addActionLabel('Añadir parámetro')
                            ->helperText('Ej: matt_word => mayoreo_cloud'),
                    ])
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('nombre')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('slug')
                    ->label('Slug')
                    ->badge()
                    ->sortable(),
                Tables\Columns\IconColumn::make('es_activo')
                    ->label('Activo')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\IconColumn::make('configuracion.es_afiliados')
                    ->label('Afiliados')
                    ->boolean()
                    ->getStateUsing(fn (Marketplace $r) => (bool) ($r->configuracion['es_afiliados'] ?? false))
                    ->sortable(query: function ($query, string $direction) {
                        $driver = $query->getConnection()->getDriverName();
                        $dir = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
                        if ($driver === 'mysql') {
                            return $query->orderByRaw("JSON_UNQUOTE(JSON_EXTRACT(configuracion, '$.es_afiliados')) {$dir}");
                        }
                        return $query->orderByRaw("json_extract(configuracion, '$.es_afiliados') {$dir}");
                    }),
                Tables\Columns\TextColumn::make('affiliate_id')
                    ->label('ID afiliado')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('app_id')
                    ->label('App ID')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('nombre')
            ->filters([])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMarketplaces::route('/'),
            'create' => Pages\CreateMarketplace::route('/create'),
            'edit' => Pages\EditMarketplace::route('/{record}/edit'),
        ];
    }
}
