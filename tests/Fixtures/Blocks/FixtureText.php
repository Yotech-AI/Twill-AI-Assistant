<?php

namespace TwillAi\Tests\Fixtures\Blocks;

use A17\Twill\Services\Forms\Fields\Input;
use A17\Twill\Services\Forms\Fields\Wysiwyg;
use A17\Twill\Services\Forms\Form;
use A17\Twill\View\Components\Blocks\TwillBlockComponent;
use Illuminate\Contracts\View\View;

/**
 * The plain block: translatable scalar fields only, no children.
 */
class FixtureText extends TwillBlockComponent
{
    public function getForm(): Form
    {
        return Form::make([
            Input::make()->name('heading')->label('Heading')->translatable(),
            Wysiwyg::make()->name('body')->label('Body')->translatable(),
        ]);
    }

    public static function getBlockIdentifier(): string
    {
        return 'fixture-text';
    }

    public static function getBlockTitle(): string
    {
        return 'Fixture Text';
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
