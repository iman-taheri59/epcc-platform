<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\ComponentController;
use App\Http\Controllers\DemandReaderController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\GateDemoController;
use App\Http\Controllers\InspectionNotificationController;
use App\Http\Controllers\InspectionResultController;
use App\Http\Controllers\ItpSignOffController;
use App\Http\Controllers\LegacyReconciliationController;
use App\Http\Controllers\MtrBindingController;
use App\Http\Controllers\MaterialsController;
use App\Http\Controllers\OrderGapController;
use App\Http\Controllers\PipingConfigController;
use App\Http\Controllers\PipingCustodyAmendmentController;
use App\Http\Controllers\PipingCustodyController;
use App\Http\Controllers\PipingGrnController;
use App\Http\Controllers\PipingLineController;
use App\Http\Controllers\PipingQaDecisionController;
use App\Http\Controllers\PipingReceiptAccessController;
use App\Http\Controllers\PipingReceivingReviewController;
use App\Http\Controllers\PipingTraceabilityController;
use App\Http\Controllers\ProjectFittingDefinitionController;
use App\Http\Controllers\ProjectSetupController;
use App\Http\Controllers\QualityNominationController;
use App\Http\Controllers\PunchController;
use App\Http\Controllers\ScopeAdmissionAuthorityController;
use App\Http\Controllers\ScopeProposalEnvelopeController;
use App\Http\Controllers\TransmittalController;
use Illuminate\Support\Facades\Route;

// ---- Gate 1: authentication ----
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
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
    Route::post('/projects/{projectCode}/piping/workspace/packs', [PipingLineController::class, 'storeTestPack'])->name('piping.test-packs.store');
    Route::post('/projects/{projectCode}/piping/workspace/packs/{packId}/transition', [PipingLineController::class, 'transitionTestPack'])->name('piping.test-packs.transition');
    Route::post('/projects/{projectCode}/piping', [PipingLineController::class, 'store'])->name('piping.store');
    Route::post('/projects/{projectCode}/piping/{lineId}/transition', [PipingLineController::class, 'transition'])->name('piping.transition');
    Route::post('/projects/{projectCode}/piping/{lineId}/evidence', [PipingLineController::class, 'attach'])->name('piping.evidence');
    Route::post('/projects/{projectCode}/piping/{lineId}/test-pack', [PipingLineController::class, 'assignTestPack'])->name('piping.test-packs.assign');

    Route::post('/projects/{projectCode}/piping/{lineId}/evidence/{linkId}/detach', [PipingLineController::class, 'detach'])->name('piping.evidence.detach');

    // Project-specific fitting/material declarations and independent review.
    // Literal project-fittings route must precede the {lineId} model route.
    Route::get('/projects/{projectCode}/piping/project-fittings', [ProjectFittingDefinitionController::class, 'index'])->name('piping.project-fittings');
    Route::post('/projects/{projectCode}/piping/project-fittings', [ProjectFittingDefinitionController::class, 'store'])->name('piping.project-fittings.store');
    Route::post('/projects/{projectCode}/piping/project-fittings/review-policy', [ProjectFittingDefinitionController::class, 'configure'])->name('piping.project-fittings.policy');
    Route::post('/projects/{projectCode}/piping/project-fittings/{definitionId}/submit', [ProjectFittingDefinitionController::class, 'submit'])->name('piping.project-fittings.submit');
    Route::post('/projects/{projectCode}/piping/project-fittings/{definitionId}/decide', [ProjectFittingDefinitionController::class, 'decide'])->name('piping.project-fittings.decide');
    Route::post('/projects/{projectCode}/piping/project-fittings/{definitionId}/correct', [ProjectFittingDefinitionController::class, 'correct'])->name('piping.project-fittings.correct');

    // Unit preferences (ADR-014). Declared before {lineId} routes for the same
    // reason config is: a literal segment must not be eaten as an id.
    Route::post('/projects/{projectCode}/piping/units', [PipingLineController::class, 'updateUnits'])->name('piping.units');

    // ADR-006 phase 2: what a line is MADE OF. A separate page from the
    // register on purpose - "which lines exist" and "what is this one made of"
    // are different working sessions.
    Route::get('/projects/{projectCode}/piping/{lineId}/model', [ComponentController::class, 'index'])->name('piping.model');
    Route::post('/projects/{projectCode}/piping/{lineId}/model', [ComponentController::class, 'store'])->name('piping.model.store');
    Route::post('/projects/{projectCode}/piping/{lineId}/model/attach', [ComponentController::class, 'attach'])->name('piping.model.attach');
    Route::post('/projects/{projectCode}/piping/{lineId}/model/{componentId}/request-review', [ComponentController::class, 'requestScheduleReview'])->name('piping.model.schedule-review');
    Route::post('/projects/{projectCode}/piping/{lineId}/model/reports/{requestId}/{mode}', [ComponentController::class, 'captureReport'])->name('piping.model.report-capture');
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
    Route::get('/projects/{projectCode}/materials/scope-proposals/{proposalId}', [ScopeProposalEnvelopeController::class, 'show'])->whereUuid('proposalId')->middleware('cache.headers:private;no_store;max_age=0')->name('materials.scope-proposals.show');
    Route::get('/projects/{projectCode}/materials/scope-admission-actors', [ScopeAdmissionAuthorityController::class, 'show'])->middleware('cache.headers:private;no_store;max_age=0')->name('materials.scope-admission-actors');
    Route::post('/projects/{projectCode}/materials/scope-admission-actors', [ScopeAdmissionAuthorityController::class, 'store'])->name('materials.scope-admission-actors.store');
    Route::get('/projects/{projectCode}/materials/scope-admission-suspensions', [ScopeAdmissionAuthorityController::class, 'suspensions'])->middleware('cache.headers:private;no_store;max_age=0')->name('materials.scope-admission-suspensions');
    Route::post('/projects/{projectCode}/materials/scope-admission-suspensions', [ScopeAdmissionAuthorityController::class, 'suspend'])->name('materials.scope-admission-suspensions.store');
    Route::get('/projects/{projectCode}/materials/scope-admission-diagnostics', [ScopeAdmissionAuthorityController::class, 'diagnostics'])->middleware('cache.headers:private;no_store;max_age=0')->name('materials.scope-admission-diagnostics');
    Route::get('/projects/{projectCode}/materials/demand-readers', [DemandReaderController::class, 'show'])->middleware('cache.headers:private;no_store;max_age=0')->name('materials.demand-readers');
    Route::post('/projects/{projectCode}/materials/demand-readers', [DemandReaderController::class, 'store'])->name('materials.demand-readers.store');
    Route::get('/projects/{projectCode}/materials/demand-reader-suspensions', [DemandReaderController::class, 'suspensions'])->middleware('cache.headers:private;no_store;max_age=0')->name('materials.demand-reader-suspensions');
    Route::post('/projects/{projectCode}/materials/demand-reader-suspensions', [DemandReaderController::class, 'suspend'])->name('materials.demand-reader-suspensions.store');
    Route::get('/projects/{projectCode}/materials/demand-readiness', [DemandReaderController::class, 'readiness'])->middleware('cache.headers:private;no_store;max_age=0')->name('materials.demand-readiness');
    Route::get('/projects/{projectCode}/materials/piping-traceability', [PipingTraceabilityController::class, 'show'])->middleware('cache.headers:private;no_store;max_age=0')->name('materials.piping-traceability');
    Route::get('/projects/{projectCode}/materials/piping-custody/{receiptId}/traceability', [PipingTraceabilityController::class, 'show'])->whereUuid('receiptId')->middleware('cache.headers:private;no_store;max_age=0')->name('materials.piping-traceability.show');
    Route::get('/projects/{projectCode}/materials/piping-custody/{receiptId}/receiving-qc', [PipingReceivingReviewController::class, 'show'])->whereUuid('receiptId')->middleware('cache.headers:private;no_store;max_age=0')->name('materials.piping-receiving-qc');
    Route::post('/projects/{projectCode}/materials/piping-custody/{receiptId}/receiving-qc', [PipingReceivingReviewController::class, 'store'])->whereUuid('receiptId')->name('materials.piping-receiving-qc.store');
    Route::get('/projects/{projectCode}/materials/piping-custody/{receiptId}/amendments', [PipingCustodyAmendmentController::class, 'show'])->whereUuid('receiptId')->middleware('cache.headers:private;no_store;max_age=0')->name('materials.piping-custody-amendments');
    Route::post('/projects/{projectCode}/materials/piping-custody/{receiptId}/amendments', [PipingCustodyAmendmentController::class, 'store'])->whereUuid('receiptId')->name('materials.piping-custody-amendments.store');
    Route::get('/projects/{projectCode}/materials/piping-custody/{receiptId}/grn', [PipingGrnController::class, 'show'])->whereUuid('receiptId')->middleware('cache.headers:private;no_store;max_age=0')->name('materials.piping-grn');
    Route::post('/projects/{projectCode}/materials/piping-custody/{receiptId}/grn', [PipingGrnController::class, 'store'])->whereUuid('receiptId')->name('materials.piping-grn.store');
    Route::get('/projects/{projectCode}/materials/piping-custody/{receiptId}/qa', [PipingQaDecisionController::class, 'show'])->whereUuid('receiptId')->middleware('cache.headers:private;no_store;max_age=0')->name('materials.piping-qa');
    Route::post('/projects/{projectCode}/materials/piping-custody/{receiptId}/qa', [PipingQaDecisionController::class, 'store'])->whereUuid('receiptId')->name('materials.piping-qa.store');
    Route::get('/projects/{projectCode}/materials/piping-custody', [PipingCustodyController::class, 'index'])->middleware('cache.headers:private;no_store;max_age=0')->name('materials.piping-custody');
    Route::post('/projects/{projectCode}/materials/piping-custody', [PipingCustodyController::class, 'store'])->name('materials.piping-custody.store');
    Route::get('/projects/{projectCode}/materials/piping-custody/{receiptId}', [PipingCustodyController::class, 'show'])->whereUuid('receiptId')->middleware('cache.headers:private;no_store;max_age=0')->name('materials.piping-custody.show');
    Route::get('/projects/{projectCode}/materials/receipt-access', [PipingReceiptAccessController::class, 'show'])->middleware('cache.headers:private;no_store;max_age=0')->name('materials.receipt-access');
    Route::post('/projects/{projectCode}/materials/receipt-access', [PipingReceiptAccessController::class, 'store'])->name('materials.receipt-access.store');

    // Quality nomination register (ADR-048 A.4g slice 1) — who may record what.
    Route::get('/projects/{projectCode}/quality/nominations', [QualityNominationController::class, 'show'])->middleware('cache.headers:private;no_store;max_age=0')->name('quality.nominations');
    Route::post('/projects/{projectCode}/quality/nominations', [QualityNominationController::class, 'store'])->name('quality.nominations.store');

    // Legacy warehouse reconciliation (ADR-048 A.4h slice 4) — the way through the cutover.
    Route::get('/projects/{projectCode}/quality/legacy', [LegacyReconciliationController::class, 'show'])->middleware('cache.headers:private;no_store;max_age=0')->name('quality.legacy');
    Route::post('/projects/{projectCode}/quality/legacy', [LegacyReconciliationController::class, 'store'])->name('quality.legacy.store');

    // MTR registry and custody bindings (ADR-048 A.4h slice 3) — register + bind.
    Route::get('/projects/{projectCode}/quality/mtr', [MtrBindingController::class, 'show'])->middleware('cache.headers:private;no_store;max_age=0')->name('quality.mtr');
    Route::post('/projects/{projectCode}/quality/mtr/certificates', [MtrBindingController::class, 'storeCertificate'])->name('quality.mtr.certificates');
    Route::post('/projects/{projectCode}/quality/mtr/bindings', [MtrBindingController::class, 'storeBinding'])->name('quality.mtr.bindings');

    // ITP stage sign-offs (ADR-048 A.4h slice 2) — what happened at the stage.
    Route::get('/projects/{projectCode}/quality/sign-offs', [ItpSignOffController::class, 'show'])->middleware('cache.headers:private;no_store;max_age=0')->name('quality.sign-offs');
    Route::post('/projects/{projectCode}/quality/sign-offs', [ItpSignOffController::class, 'store'])->name('quality.sign-offs.store');

    // Recording inspection results (ADR-048 A.4h slice 1) — weld, NDT, test pack.
    Route::get('/projects/{projectCode}/quality/results', [InspectionResultController::class, 'show'])->middleware('cache.headers:private;no_store;max_age=0')->name('quality.results');
    Route::post('/projects/{projectCode}/quality/results/weld', [InspectionResultController::class, 'storeWeld'])->name('quality.results.weld');
    Route::post('/projects/{projectCode}/quality/results/ndt', [InspectionResultController::class, 'storeNdt'])->name('quality.results.ndt');
    Route::post('/projects/{projectCode}/quality/results/pack', [InspectionResultController::class, 'storePack'])->name('quality.results.pack');
    Route::get('/projects/{projectCode}/materials', [MaterialsController::class, 'warehouse'])->name('materials');
    Route::post('/projects/{projectCode}/materials/issue', [MaterialsController::class, 'issue'])->name('materials.issue');
    Route::post('/projects/{projectCode}/materials/deliver', [MaterialsController::class, 'deliver'])->name('materials.deliver');
    Route::post('/projects/{projectCode}/materials/reverse', [MaterialsController::class, 'reverse'])->name('materials.reverse');

    Route::get('/projects/{projectCode}/inspection-notifications/prerequisites', [InspectionNotificationController::class, 'prerequisiteRegister'])->middleware('cache.headers:private;no_store;max_age=0')->name('inspection-notifications.prerequisites.index');
    Route::get('/projects/{projectCode}/inspection-notifications', [InspectionNotificationController::class, 'index'])->name('inspection-notifications.index');
    Route::post('/projects/{projectCode}/inspection-notifications/settings', [InspectionNotificationController::class, 'configure'])->name('inspection-notifications.settings');
    Route::post('/projects/{projectCode}/inspection-notifications/stages', [InspectionNotificationController::class, 'stage'])->name('inspection-notifications.stages');
    Route::post('/projects/{projectCode}/inspection-notifications', [InspectionNotificationController::class, 'store'])->name('inspection-notifications.store');
    Route::get('/projects/{projectCode}/inspection-notifications/{notificationId}/edit', [InspectionNotificationController::class, 'edit'])->whereUuid('notificationId')->name('inspection-notifications.edit');
    Route::put('/projects/{projectCode}/inspection-notifications/{notificationId}', [InspectionNotificationController::class, 'update'])->whereUuid('notificationId')->name('inspection-notifications.update');
    Route::get('/projects/{projectCode}/inspection-notifications/{notificationId}.pdf', [InspectionNotificationController::class, 'pdf'])->whereUuid('notificationId')->name('inspection-notifications.pdf');
    Route::get('/projects/{projectCode}/inspection-notifications/{notificationId}', [InspectionNotificationController::class, 'show'])->whereUuid('notificationId')->name('inspection-notifications.show');
    Route::post('/projects/{projectCode}/inspection-notifications/{notificationId}/prerequisites', [InspectionNotificationController::class, 'prerequisiteStore'])->whereUuid('notificationId')->name('inspection-notifications.prerequisites.store');
    Route::get('/projects/{projectCode}/inspection-notifications/{notificationId}/prerequisites/{prerequisiteId}', [InspectionNotificationController::class, 'prerequisiteShow'])->whereUuid('notificationId')->whereUuid('prerequisiteId')->name('inspection-notifications.prerequisites.show');
    Route::post('/projects/{projectCode}/inspection-notifications/{notificationId}/prerequisites/{prerequisiteId}', [InspectionNotificationController::class, 'prerequisiteUpdate'])->whereUuid('notificationId')->whereUuid('prerequisiteId')->name('inspection-notifications.prerequisites.update');
    Route::post('/projects/{projectCode}/inspection-notifications/{notificationId}/acknowledge', [InspectionNotificationController::class, 'acknowledge'])->whereUuid('notificationId')->name('inspection-notifications.acknowledge');
    Route::post('/projects/{projectCode}/inspection-notifications/{notificationId}/cancel', [InspectionNotificationController::class, 'cancel'])->whereUuid('notificationId')->name('inspection-notifications.cancel');
    Route::post('/projects/{projectCode}/inspection-notifications/{notificationId}/issue', [InspectionNotificationController::class, 'issue'])->whereUuid('notificationId')->name('inspection-notifications.issue');

    Route::get('/projects/{projectCode}/procurement', [MaterialsController::class, 'procurement'])->name('procurement');
    Route::get('/projects/{projectCode}/procurement/execution/{buyerId}', [MaterialsController::class, 'purchaseOrderExecutionWorkspace'])->whereUuid('buyerId')->name('procurement.execution');
    Route::get('/projects/{projectCode}/procurement/execution/{buyerId}/{orderId}', [MaterialsController::class, 'purchaseOrderExecutionWorkspace'])->whereUuid('buyerId')->whereUuid('orderId')->name('procurement.execution.order');
    Route::post('/projects/{projectCode}/procurement/execution/{buyerId}/{orderId}/{purpose}', [MaterialsController::class, 'executePurchaseOrder'])->whereUuid('buyerId')->whereUuid('orderId')->whereIn('purpose', ['finance', 'issue'])->name('procurement.execution.act');
    Route::get('/projects/{projectCode}/procurement/requisitions/{requisitionId}', [MaterialsController::class, 'requisition'])->whereUuid('requisitionId')->name('procurement.requisitions.show');
    Route::get('/projects/{projectCode}/procurement/orders/{orderId}', [MaterialsController::class, 'purchaseOrder'])->whereUuid('orderId')->name('procurement.orders.show');
    Route::get('/projects/{projectCode}/procurement/orders/{orderId}/source-review', [MaterialsController::class, 'purchaseOrderSourceReview'])->whereUuid('orderId')->name('procurement.orders.source-review');
    Route::post('/projects/{projectCode}/procurement/orders', [MaterialsController::class, 'createPurchaseOrder'])->name('procurement.orders.create');
    Route::post('/projects/{projectCode}/procurement/orders/from-requisition', [MaterialsController::class, 'createPurchaseOrderFromRequisition'])->name('procurement.orders.from-requisition');
    Route::post('/projects/{projectCode}/procurement/orders/{orderId}/pricing', [MaterialsController::class, 'pricePurchaseOrder'])->whereUuid('orderId')->name('procurement.orders.pricing');
    Route::post('/projects/{projectCode}/procurement/order-author-access', [MaterialsController::class, 'configurePurchaseOrderAuthors'])->name('procurement.order-author-access');
    Route::post('/projects/{projectCode}/procurement/order-execution-access', [MaterialsController::class, 'configurePurchaseOrderExecutionAccess'])->name('procurement.order-execution-access');
    Route::post('/projects/{projectCode}/procurement/requisitions', [MaterialsController::class, 'createRequisition'])->name('procurement.requisitions.create');
    Route::post('/projects/{projectCode}/procurement/inspection-author-access', [MaterialsController::class, 'configureInspectionAuthors'])->name('procurement.inspection-author-access');
    Route::post('/projects/{projectCode}/procurement/orders/{orderId}/inspections', [MaterialsController::class, 'recordInspection'])->whereUuid('orderId')->name('procurement.orders.inspections.create');
    Route::post('/projects/{projectCode}/procurement/author-access', [MaterialsController::class, 'configureRequisitionAuthors'])->name('procurement.author-access');
    Route::post('/projects/{projectCode}/procurement/requisition', [MaterialsController::class, 'advanceRequisition'])->name('procurement.requisition');
    Route::post('/projects/{projectCode}/procurement/forecast', [MaterialsController::class, 'forecast'])->name('procurement.forecast');

    // Order gap board (ADR-015, MTO Slice 6). The gap is computed, never
    // written onto the accepted rows; the three POST paths are the only
    // attribution/amendment/allocation entry points.
    Route::get('/projects/{projectCode}/procurement/order-gap', [OrderGapController::class, 'index'])->name('procurement.order-gap');
    Route::post('/projects/{projectCode}/procurement/order-gap/attribute', [OrderGapController::class, 'attribute'])->name('procurement.order-gap.attribute');
    Route::post('/projects/{projectCode}/procurement/order-gap/amend', [OrderGapController::class, 'amend'])->name('procurement.order-gap.amend');
    Route::post('/projects/{projectCode}/procurement/order-gap/allocate', [OrderGapController::class, 'allocate'])->name('procurement.order-gap.allocate');

    Route::get('/projects/{projectCode}/team', [ProjectSetupController::class, 'team'])->name('projects.team');
    Route::post('/projects/{projectCode}/team', [ProjectSetupController::class, 'addMember'])->name('projects.team.add');
});

// Stateless engine playground: no database, no tenant, no login.
Route::get('/gate', GateDemoController::class)->name('gate');
