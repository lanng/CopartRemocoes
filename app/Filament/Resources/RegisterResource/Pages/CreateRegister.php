<?php

namespace App\Filament\Resources\RegisterResource\Pages;

use App\Filament\Resources\RegisterResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;

class CreateRegister extends CreateRecord
{
    protected static string $resource = RegisterResource::class;

    protected function getRedirectUrl(): string
    {
        return RegisterResource::getUrl('index');
    }
}
