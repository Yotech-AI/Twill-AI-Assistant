<?php

namespace TwillAi\Tests\Fixtures\Blocks;

use A17\Twill\Services\Forms\Fields\BlockEditor;
use A17\Twill\Services\Forms\Fields\Browser;
use A17\Twill\Services\Forms\Fields\Input;
use A17\Twill\Services\Forms\Fields\Medias;
use A17\Twill\Services\Forms\Form;
use A17\Twill\View\Components\Blocks\TwillBlockComponent;
use Illuminate\Contracts\View\View;
use TwillAi\Tests\Fixtures\Models\Article;

/**
 * The nested-editor block. Its children arrive under "children" keyed by the
 * editor name as FULLY TYPED blocks ({"type": "...", "content": {...}}), and it
 * also carries a media role and a browser so the schema reflection has one
 * block exercising every branch of applyFieldToSchema().
 */
class FixtureSection extends TwillBlockComponent
{
    public function getForm(): Form
    {
        return Form::make([
            Input::make()->name('label')->label('Section label')->translatable(),

            Medias::make()->name('background')->label('Background')->max(1),

            Browser::make()
                ->name('related_articles')
                ->label('Related articles')
                ->max(3)
                ->modules([Article::class]),

            BlockEditor::make()
                ->name('section_content')
                ->blocks(['fixture-text', 'fixture-faq']),
        ]);
    }

    public static function getCrops(): array
    {
        return [
            'background' => [
                'default' => [
                    [
                        'name' => 'default',
                        'ratio' => 16 / 9,
                    ],
                ],
            ],
        ];
    }

    public static function getBlockIdentifier(): string
    {
        return 'fixture-section';
    }

    public static function getBlockTitle(): string
    {
        return 'Fixture Section';
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
