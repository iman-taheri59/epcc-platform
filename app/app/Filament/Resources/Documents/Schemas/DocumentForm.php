<?php

declare(strict_types=1);

namespace App\Filament\Resources\Documents\Schemas;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The admin edit form for a document.
 *
 * WHAT IS EDITABLE HERE, AND WHY SO LITTLE
 * ----------------------------------------
 * The generated scaffold offered every column as an input: number, sequence,
 * company_id, project_id, current_revision_id. That is a faithful rendering of
 * the table and a dangerous rendering of the domain.
 *
 *   - `number` and `sequence` are DERIVED by DocumentRegister from the
 *     project's per-discipline/per-type counter. Retyping a number over an
 *     existing drawing's is not an edit, it is a collision - and the unique
 *     index on (project_id, number) would either reject it with a 500 or,
 *     worse, succeed against a number the counter will later reissue.
 *   - `company_id` is the RLS tenant anchor. Changing it does not "move" a
 *     document; it makes the document vanish from the register of the company
 *     that authored it.
 *   - `current_revision_id` is maintained by the revision workflow. Pointing it
 *     at an arbitrary revision decouples the register from its own history.
 *   - `discipline` and `doc_type` are BAKED INTO the number. Editing them here
 *     would leave PH-14-CIVIL-DWG-0001 filed under PIPING.
 *
 * So the form edits the two things that are genuinely free text and carry no
 * structural meaning: the bilingual titles. Everything else is shown read-only,
 * because an admin still needs to SEE it to know which record they have.
 *
 * Creating documents from here is deliberately not supported - see
 * DocumentResource::canCreate(). A document must be born through
 * DocumentRegister so it gets a number and an opening WIP revision.
 */
class DocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('epcc.doc.identity'))
                    ->description(__('epcc.doc.identity_hint'))
                    ->schema([
                        Placeholder::make('number_display')
                            ->label(__('epcc.doc.number'))
                            ->content(fn ($record) => $record?->number ?? '—'),

                        Placeholder::make('discipline_display')
                            ->label(__('epcc.doc.discipline'))
                            ->content(fn ($record) => $record?->discipline ?? '—'),

                        Placeholder::make('type_display')
                            ->label(__('epcc.doc.type'))
                            ->content(fn ($record) => $record?->doc_type ?? '—'),

                        Placeholder::make('owner_display')
                            ->label(__('epcc.doc.owner'))
                            ->content(fn ($record) => $record?->company?->code ?? '—'),
                    ])
                    ->columns(2),

                Section::make(__('epcc.doc.titles'))
                    ->schema([
                        TextInput::make('title_fa')
                            ->label(__('epcc.doc.title_fa'))
                            ->required()
                            ->maxLength(255),

                        TextInput::make('title_en')
                            ->label(__('epcc.doc.title_en'))
                            ->required()
                            ->maxLength(255)
                            // The English title stays LTR even on an RTL page.
                            ->extraInputAttributes(['dir' => 'ltr']),
                    ])
                    ->columns(1),
            ]);
    }
}
