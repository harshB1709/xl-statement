<?php

use App\Livewire\Converter;
use Livewire\Livewire;

it('renders the converter home page', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSeeLivewire(Converter::class);
});

it('starts on the files step', function () {
    Livewire::test(Converter::class)
        ->assertSet('step', 1)
        ->assertSee('Choose PDFs');
});
