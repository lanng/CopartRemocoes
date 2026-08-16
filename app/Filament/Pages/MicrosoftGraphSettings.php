<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class MicrosoftGraphSettings extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'Financeiro';

    protected static ?string $navigationLabel = 'Outlook';

    protected static ?string $title = 'Integração Outlook';

    protected static string $view = 'filament.pages.microsoft-graph-settings';
}
