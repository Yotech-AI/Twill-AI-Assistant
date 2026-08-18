<?php

namespace TwillAi\Tests\Fixtures\Blocks;

use A17\Twill\Services\Forms\Fields\Input;
use A17\Twill\Services\Forms\Form;
use A17\Twill\Services\Forms\InlineRepeater;
use A17\Twill\View\Components\Blocks\TwillBlockComponent;
use Illuminate\Contracts\View\View;

/**
 * The inline-repeater block. Its children arrive under "children" keyed by the
 * repeater name, as UNTYPED items ({"content": {...}}) — the half of the
 * children contract that FixtureSection's nested editor does not cover.
 */
class FixtureFaq extends TwillBlockComponent
{
    public function getForm(): Form
    {
        return Form::make([
            Input::make()->name('intro')->label('Intro')->translatable(),

            InlineRepeater::make()
                ->name('faq_items')
                ->label('FAQ items')
                ->fields([
                    Input::make()->name('question')->label('Question')->translatable(),
                    Input::make()->name('answer')->label('Answer')->translatable(),
                ]),
        ]);
    }

    public static function getBlockIdentifier(): string
    {
        return 'fixture-faq';
    }

    public static function getBlockTitle(): string
    {
        return 'Fixture FAQ';
    }

    public static function getBlockGroup(): string
    {
        return 'fixture';
    }

    public function render(): View
    {
        return view('twill-ai-fixtures::block');
    }
}
