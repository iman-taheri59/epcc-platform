<?php

namespace App\Filament\Resources\Documents;

use App\Filament\Resources\Documents\Pages\EditDocument;
use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Filament\Resources\Documents\Schemas\DocumentForm;
use App\Filament\Resources\Documents\Tables\DocumentsTable;
use App\Models\Document;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Admin CRUD over the document register.
 *
 * This is an ADMINISTRATIVE view, not a replacement for the hand-written
 * register at /projects/{code}/documents. That screen enforces the workflow -
 * numbering through DocumentRegister, state transitions through CdeState,
 * review gating through ReviewCycle. This one exists for the cases the
 * workflow does not cover: correcting a typo in a title, looking a record up
 * across projects, inspecting what is actually stored.
 *
 * Both are subject to the same RLS, because tenancy is enforced in PostgreSQL
 * rather than in either screen.
 */
class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getModelLabel(): string
    {
        return __('epcc.doc.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('epcc.doc.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('epcc.nav.engineering');
    }

    /**
     * Documents are not created here.
     *
     * DocumentRegister assigns the number from the project's per-discipline
     * counter and opens the first WIP revision in the same transaction. A bare
     * insert from an admin form produces a document with neither, so the
     * capability is withdrawn rather than half-implemented.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Nor deleted. Revisions supersede, states archive - the register keeps
     * its history by design (ISO 19650, and the reason DocumentRegister
     * archives rather than overwrites).
     */
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /** The register is identified by number, never by UUID. */
    public static function getRecordTitleAttribute(): ?string
    {
        return 'number';
    }

    public static function form(Schema $schema): Schema
    {
        return DocumentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DocumentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocuments::route('/'),
            'edit' => EditDocument::route('/{record}/edit'),
        ];
    }
}
