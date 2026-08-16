<?php

namespace App\Filament\Pages;

use App\Models\CteAgent;
use Filament\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

class CteAgentSettings extends Page implements HasForms
{
    use InteractsWithForms;

    public ?array $data = [];

    public ?string $generatedToken = null;

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationGroup = 'CT-e';

    protected static ?string $navigationLabel = 'Agente CT-e';

    protected static ?string $title = 'Agente CT-e';

    protected static string $view = 'filament.pages.cte-agent-settings';

    public function mount(): void
    {
        $agent = $this->getAgent();

        $this->form->fill([
            'name' => $agent?->name ?? 'CT-e Agent',
            'hostname' => $agent?->hostname,
            'is_dry_run' => $agent?->is_dry_run ?? true,
            'is_active' => $agent?->is_active ?? true,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Configuração')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('hostname')
                            ->label('Hostname')
                            ->maxLength(255),
                        Toggle::make('is_dry_run')
                            ->label('Modo simulação'),
                        Toggle::make('is_active')
                            ->label('Agente ativo'),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateToken')
                ->label('Gerar novo token')
                ->icon('heroicon-o-key')
                ->color('warning')
                ->disabled(fn (): bool => $this->getAgent() === null)
                ->requiresConfirmation()
                ->modalHeading('Gerar novo token')
                ->modalDescription('O token atual deixará de funcionar imediatamente. Gere um novo apenas se você puder atualizar o worker da máquina host.')
                ->modalSubmitActionLabel('Gerar token')
                ->action(function (): void {
                    $this->generateToken();
                }),
        ];
    }

    public function save(): void
    {
        $agent = CteAgent::query()->firstOrNew([
            'singleton_key' => CteAgent::SINGLETON_KEY,
        ]);
        $agent->fill($this->form->getState());
        $agent->save();

        Notification::make()
            ->success()
            ->title('Configuração do agente salva.')
            ->send();
    }

    public function generateToken(): void
    {
        $agent = $this->getAgent();

        if (! $agent) {
            Notification::make()
                ->warning()
                ->title('Salve a configuração do agente primeiro.')
                ->send();

            return;
        }

        $newAccessToken = DB::transaction(function () use ($agent): NewAccessToken {
            $agent->tokens()->delete();

            return $agent->createToken('cte-agent-host', ['cte-agent']);
        });

        $this->generatedToken = $newAccessToken->plainTextToken;

        Notification::make()
            ->success()
            ->title('Novo token gerado.')
            ->body('Copie o token exibido na página e atualize o worker da máquina host.')
            ->send();
    }

    public function getAgent(): ?CteAgent
    {
        return CteAgent::query()
            ->where('singleton_key', CteAgent::SINGLETON_KEY)
            ->first();
    }

    public function getLatestToken(): ?PersonalAccessToken
    {
        return $this->getAgent()?->tokens()->latest('created_at')->first();
    }
}
