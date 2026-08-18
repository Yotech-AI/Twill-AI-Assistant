<?php

namespace TwillAi\Tests\Fixtures\Blocks;

use A17\Twill\Services\Forms\Fields\Input;
use A17\Twill\Services\Forms\Form;
use A17\Twill\View\Components\Blocks\TwillBlockComponent;
use Illuminate\Contracts\View\View;

/**
 * A block that is registered with Twill but allowed in the SINGLETON's editor
 * only. It exists so the guard tests can offer a real, resolvable block to the
 * wrong editor and prove the registry's per-editor allow-list refuses it —
 * mirroring pomofit's `home-brandbox`, which belongs to the homepage alone.
 */
class FixtureBanner extends TwillBlockComponent
{
    public function getForm(): Form
    {
        return Form::make([
            Input::make()->name('message')->label('Message')->translatable(),
        ]);
    }

    public static function getBlockIdentifier(): string
    {
        return 'fixture-banner';
    }

    public static function getBlockTitle(): string
    {
        return 'Fixture Banner';
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
