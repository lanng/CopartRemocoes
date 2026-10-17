<?php

namespace Tests\Unit\Enums;

use App\Enums\RegisterStatusEnum;
use Tests\TestCase;

class RegisterStatusLabelTest extends TestCase
{
    public function test_analysis_has_translated_labels_and_an_existing_warning_color(): void
    {
        $status = RegisterStatusEnum::from('analysis');

        $this->assertSame('Analysis', $status->label());
        $this->assertSame('Análise', $status->labelPt());
        $this->assertSame('warning', $status->color());
        app()->setLocale('pt_BR');
        $this->assertSame('Análise', RegisterStatusEnum::optionsWithLabels()['analysis']);
    }
}
