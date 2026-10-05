<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\MaterialsController;
use App\Http\Controllers\PipingConfigController;
use App\Http\Controllers\ComponentController;
use App\Http\Controllers\PipingLineController;
use App\Http\Controllers\PunchController;
use App\Http\Controllers\TransmittalController;
use App\Http\Controllers\GateDemoController;
use App\Http\Controllers\ProjectSetupController;
use Illuminate\Support\Facades\Route;

// ---- Gate 1: authentication ----
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

// ---- Application: authenticated AND tenant-bound ----
// Order matters: `auth` must run before `tenant`, because the tenant to bind
// IS the authenticated user's company.
Route::middleware(['auth', 'tenant'])->group(function (): void {
    Route::get('/', [CertificateController::class, 'index'])->name('portfolio');

    // Setup routes come BEFORE /projects/{code} so "new" is not swallowed
    // by the wildcard.
    Route::get('/projects/new', [ProjectSetupController::class, 'create'])->name('projects.create');
    Route::post('/projects', [ProjectSetupController::class, 'store'])->name('projects.store');

    // Close-out (ADR-013 §7.2). POST, because archiving takes write access
    // away from every other company on the job.
    Route::post('/projects/{projectCode}/archive', [ProjectSetupController::class, 'archive'])->name('projects.archive');
    Route::post('/projects/{projectCode}/reopen', [ProjectSetupController::class, 'reopen'])->name('projects.reopen');

    Route::get('/projects/{projectCode}', [CertificateController::class, 'show'])->name('project');

    // Document register / CDE. Under the project, because a deliverable
    // without a project has no number scheme and no RLS scope.
    Route::get('/projects/{projectCode}/documents', [DocumentController::class, 'index'])->name('documents');
    Route::post('/projects/{projectCode}/documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::post('/projects/{projectCode}/documents/{documentId}', [DocumentController::class, 'update'])->name('documents.update');

    // Attachments (FR-DOC-01/02). Downloads are streamed through the
    // controller, never from a public disk, so every byte passes the same
    // auth + entitlement + RLS path as the register screen itself.
    Route::post('/projects/{projectCode}/documents/{documentId}/files', [DocumentController::class, 'upload'])->name('documents.files.upload');
    Route::get('/projects/{projectCode}/documents/{documentId}/files/{fileId}', [DocumentController::class, 'download'])->name('documents.files.download');
    Route::post('/projects/{projectCode}/documents/{documentId}/files/{fileId}/delete', [DocumentController::class, 'deleteFile'])->name('documents.files.delete');

    // Review & comment sheet (FR-ENG-06).
    Route::get('/projects/{projectCode}/documents/{documentId}/review', [DocumentController::class, 'review'])->name('documents.review');
    Route::post('/projects/{projectCode}/documents/{documentId}/review', [DocumentController::class, 'comment'])->name('documents.review.comment');
    Route::post('/projects/{projectCode}/documents/{documentId}/review/{commentId}', [DocumentController::class, 'dispose'])->name('documents.review.dispose');

    // Transmittals (FR-ENG-05).
    Route::get('/projects/{projectCode}/transmittals', [TransmittalController::class, 'index'])->name('transmittals');
    Route::post('/projects/{projectCode}/transmittals', [TransmittalController::class, 'store'])->name('transmittals.store');
    Route::post('/projects/{projectCode}/transmittals/{transmittalId}', [TransmittalController::class, 'update'])->name('transmittals.update');
    Route::get('/projects/{projectCode}/transmittals/{transmittalId}/download', [TransmittalController::class, 'download'])->name('transmittals.download');
    Route::get('/projects/{projectCode}/transmittals/{transmittalId}/sheet.pdf', [TransmittalController::class, 'sheet'])->name('transmittals.sheet');

    // Vendor stream (FR-ENG-05b): supplier -> EPC, recorded by the EPC.
    // A separate route, not a query parameter, so the two registers are
    // distinct places with distinct URLs people can bookmark and link to.
    Route::get('/projects/{projectCode}/vendor-transmittals', [TransmittalController::class, 'vendorIndex'])->name('transmittals.vendor');
    Route::post('/projects/{projectCode}/vendor-transmittals/{transmittalId}/rollup', [TransmittalController::class, 'rollUp'])->name('transmittals.rollup');

    // Piping line register + AFC gate (ADR-006 phase 1).
    Route::get('/projects/{projectCode}/piping', [PipingLineController::class, 'index'])->name('piping');
    Route::get('/projects/{projectCode}/piping/workspace', [PipingLineController::class, 'workspace'])->name('piping.workspace');
    Route::post('/projects/{projectCode}/piping', [PipingLineController::class, 'store'])->name('piping.store');
    Route::post('/projects/{projectCode}/piping/{lineId}/transition', [PipingLineController::class, 'transition'])->name('piping.transition');
    Route::post('/projects/{projectCode}/piping/{lineId}/evidence', [PipingLineController::class, 'attach'])->name('piping.evidence');

    Route::post('/projects/{projectCode}/piping/{lineId}/evidence/{linkId}/detach', [PipingLineController::class, 'detach'])->name('piping.evidence.detach');

    // Unit preferences (ADR-014). Declared before {lineId} routes for the same
    // reason config is: a literal segment must not be eaten as an id.
    Route::post('/projects/{projectCode}/piping/units', [PipingLineController::class, 'updateUnits'])->name('piping.units');

    // ADR-006 phase 2: what a line is MADE OF. A separate page from the
    // register on purpose - "which lines exist" and "what is this one made of"
    // are different working sessions.
    Route::get('/projects/{projectCode}/piping/{lineId}/model', [ComponentController::class, 'index'])->name('piping.model');
    Route::post('/projects/{projectCode}/piping/{lineId}/model', [ComponentController::class, 'store'])->name('piping.model.store');
    Route::post('/projects/{projectCode}/piping/{lineId}/model/attach', [ComponentController::class, 'attach'])->name('piping.model.attach');
    Route::post('/projects/{projectCode}/piping/{lineId}/model/{componentId}/delete', [ComponentController::class, 'destroy'])->name('piping.model.destroy');

    // The numbering editor is a setup screen, not part of daily line entry.
    Route::get('/projects/{projectCode}/piping/config', [PipingConfigController::class, 'edit'])->name('piping.config');
    Route::post('/projects/{projectCode}/piping/config/pattern', [PipingConfigController::class, 'updatePattern'])->name('piping.config.pattern');
    Route::post('/projects/{projectCode}/piping/config/register', [PipingConfigController::class, 'updateRegister'])->name('piping.config.register');

    Route::get('/projects/{projectCode}/punch', [PunchController::class, 'index'])->name('punch');
    Route::post('/projects/{projectCode}/punch', [PunchController::class, 'store'])->name('punch.store');
    Route::post('/projects/{projectCode}/punch/{itemId}', [PunchController::class, 'update'])->name('punch.update');
    Route::post('/projects/{projectCode}/subsystems/{subsystemId}/certificate', [PunchController::class, 'certificate'])->name('punch.certificate');
    Route::get('/projects/{projectCode}/subsystems/{subsystemId}/certificate.pdf', [PunchController::class, 'certificatePdf'])->name('punch.certificate.pdf');
    /*
     * PROCUREMENT & MATERIALS - the second C in EPCC.
     * تهیه / بازرسی / ورود به انبار / خروج از انبار / ورود به سایت
     */
    Route::get('/projects/{projectCode}/materials', [MaterialsController::class, 'warehouse'])->name('materials');
    Route::post('/projects/{projectCode}/materials/issue', [MaterialsController::class, 'issue'])->name('materials.issue');
    Route::post('/projects/{projectCode}/materials/deliver', [MaterialsController::class, 'deliver'])->name('materials.deliver');
    Route::post('/projects/{projectCode}/materials/reverse', [MaterialsController::class, 'reverse'])->name('materials.reverse');

    Route::get('/projects/{projectCode}/procurement', [MaterialsController::class, 'procurement'])->name('procurement');
    Route::post('/projects/{projectCode}/procurement/requisition', [MaterialsController::class, 'advanceRequisition'])->name('procurement.requisition');
    Route::post('/projects/{projectCode}/procurement/forecast', [MaterialsController::class, 'forecast'])->name('procurement.forecast');

    Route::get('/projects/{projectCode}/team', [ProjectSetupController::class, 'team'])->name('projects.team');
    Route::post('/projects/{projectCode}/team', [ProjectSetupController::class, 'addMember'])->name('projects.team.add');
});

// Stateless engine playground: no database, no tenant, no login.
Route::get('/gate', GateDemoController::class)->name('gate');
