<?php

namespace App\Filament\Resources\RegisterResource\Pages;

use App\Filament\Resources\RegisterResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;
use Twilio\Rest\Client;

class CreateRegister extends CreateRecord
{
    protected static string $resource = RegisterResource::class;

    protected function getRedirectUrl(): string
    {
        return RegisterResource::getUrl('index');
    }

    /*
     * Maybe for the future, at the moment the twilio feature doesn't fit.
     *
    protected function afterCreate(): void
    {
        $createdRecord = $this->record;

        $pdf = Storage::disk('s3')->url($createdRecord->pdf_path);

        $client = new Client(config('twilio.account_sid'), config('twilio.auth_token'));

        $participants = config('twilio.whatsapp_number_receipts');

        foreach ($participants as $participant) {
            $client->messages->create($participant, [
                'from' => "whatsapp:" . config('twilio.whatsapp_number'),
                'body' => 'pdf enviado.',
                'mediaUrl' => [$pdf]
            ]);
        }
    }
    */
}
