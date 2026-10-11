<?php

use App\Models\CteNumberSequence;
use App\Models\MdfeNumberSequence;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['cte_number_sequences' => 'serie', 'mdfe_number_sequences' => 'series'] as $name => $series) {
            Schema::table($name, function (Blueprint $table) use ($name, $series): void {
                $table->unsignedTinyInteger('tp_amb')->default(0);
                $table->dropUnique(['cnpj', $series]);
                $table->unique(['cnpj', $series, 'tp_amb'], $name.'_identity_unique');
            });
        }
    }

    public function down(): void
    {
        foreach ([CteNumberSequence::class => 'serie', MdfeNumberSequence::class => 'series'] as $model => $series) {
            $duplicates = $model::query()->get()->groupBy(fn ($sequence): string => $sequence->cnpj.'|'.$sequence->{$series});
            if ($duplicates->contains(fn ($sequences): bool => $sequences->count() > 1)) {
                throw new RuntimeException('Não é possível remover o ambiente sem misturar sequências de homologação e produção.');
            }
        }

        foreach (['cte_number_sequences' => 'serie', 'mdfe_number_sequences' => 'series'] as $name => $series) {
            Schema::table($name, function (Blueprint $table) use ($name, $series): void {
                $table->dropUnique($name.'_identity_unique');
                $table->dropColumn('tp_amb');
                $table->unique(['cnpj', $series]);
            });
        }
    }
};
