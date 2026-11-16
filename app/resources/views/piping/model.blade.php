@php
    $fa = $lang === 'fa';
    $t = [
        'title'    => ['fa' => 'مدل خط', 'en' => 'Line model'],
        'sub'      => ['fa' => 'قطعات روی این خط و سرهای بازی که هنوز به چیزی وصل نیستند',
                       'en' => 'The components on this line and the ends not yet connected to anything'],
        'back'     => ['fa' => 'بازگشت به فهرست خطوط', 'en' => 'Back to the line register'],
        'geometry' => ['fa' => 'جهت‌گیری و هندسه', 'en' => 'Orientation & geometry'],
        'modelFrame' => ['fa' => 'فریم مدل پروژه', 'en' => 'Project model frame'],
        'frameNone' => ['fa' => 'فریم مختصات پروژه هنوز تعریف نشده است؛ جهت‌گیری بدون فریم صریح قابل ثبت نیست.', 'en' => 'No project coordinate frame is defined yet; orientation cannot be saved without an explicit frame.'],
        'frameRevision' => ['fa' => 'بازنگری فریم', 'en' => 'Frame revision'],
        'frameOrigin' => ['fa' => 'شرح صریح مبدأ مختصات مدل', 'en' => 'Explicit model-coordinate origin description'],
        'frameAxes' => ['fa' => 'دست‌راست: X=شرق، Y=شمال، Z=بالا · واحد MM', 'en' => 'Right-handed: X=Easting, Y=Northing, Z=Up · unit MM'],
        'frameHelp' => ['fa' => 'شرح مبدأ باید مشخص کند مختصات نسبت به چه مبنای پروژه‌ای ثبت می‌شوند؛ سامانه مبدأ جغرافیایی یا نقطه‌ای را حدس نمی‌زند.', 'en' => 'Describe the project datum for these coordinates; the system does not infer a geographic datum or origin point.'],
        'frameSave' => ['fa' => 'ثبت/بازنگری فریم', 'en' => 'Save/revise frame'],
        'poseForm' => ['fa' => 'ثبت جهت‌گیری دستی (درجه)', 'en' => 'Record manual orientation (degrees)'],
        'trianglePoseForm' => ['fa' => 'ثبت مثلث بُعددار run/rise', 'en' => 'Record a dimensioned run/rise triangle'],
        'triangleHelp' => ['fa' => 'آزیموت صفحه و رول صریح‌اند و حدس زده نمی‌شوند. run افقی و نامنفی در جهت آزیموت است؛ rise در راستای +Z علامت‌دار است. میل از atan2(rise, run) محاسبه می‌شود. همهٔ طول‌ها mm هستند. هیپوتنوز اندازه‌گیری‌شده اختیاری است؛ residual برابر measured − hypot(run, rise) فقط گزارش می‌شود، نه PASS/FAIL.', 'en' => 'Plane azimuth and roll are explicit and never inferred. Horizontal run is non-negative along azimuth; rise is signed along +Z. Inclination is atan2(rise, run). Lengths are in mm. A measured hypotenuse is optional; measured − hypot(run, rise) is reported as a raw residual, not PASS/FAIL.'],
        'azimuth' => ['fa' => 'آزیموت α', 'en' => 'Azimuth α'],
        'triangleAzimuth' => ['fa' => 'آزیموت صفحه α', 'en' => 'Plane azimuth α'],
        'inclination' => ['fa' => 'میل ε', 'en' => 'Inclination ε'],
        'roll' => ['fa' => 'رول ρ', 'en' => 'Roll ρ'],
        'run' => ['fa' => 'run افقی (mm)', 'en' => 'Horizontal run (mm)'],
        'rise' => ['fa' => 'rise علامت‌دار در +Z (mm)', 'en' => 'Signed rise along +Z (mm)'],
        'measuredHypotenuse' => ['fa' => 'هیپوتنوز اندازه‌گیری‌شده، اختیاری (mm)', 'en' => 'Measured hypotenuse, optional (mm)'],
        'calculatedHypotenuse' => ['fa' => 'هیپوتنوز محاسبه‌شده', 'en' => 'Calculated hypotenuse'],
        'triangleRaw' => ['fa' => 'ورودی خام مثلث و روش محاسبه', 'en' => 'Raw triangle input and calculation provenance'],
        'triangleResidual' => ['fa' => 'باقیماندهٔ خام closure', 'en' => 'Raw closure residual'],
        'residualUnassessed' => ['fa' => 'UNASSESSED — قاعدهٔ تلرانس پذیرش مهندسی تعریف نشده است.', 'en' => 'UNASSESSED — no engineering acceptance tolerance is defined.'],
        'anchorE' => ['fa' => 'لنگر: Easting (mm)', 'en' => 'Anchor: Easting (mm)'],
        'anchorN' => ['fa' => 'لنگر: Northing (mm)', 'en' => 'Anchor: Northing (mm)'],
        'anchorZ' => ['fa' => 'لنگر: ارتفاع (mm)', 'en' => 'Anchor: elevation (mm)'],
        'poseSave' => ['fa' => 'ثبت بازنگری جهت‌گیری', 'en' => 'Save pose revision'],
        'poseNone' => ['fa' => 'هنوز جهت‌گیری دستی ثبت نشده است.', 'en' => 'No manual pose has been recorded.'],
        'poseRevision' => ['fa' => 'بازنگری جهت‌گیری', 'en' => 'Pose revision'],
        'geometryStatus' => ['fa' => 'وضعیت هندسه', 'en' => 'Geometry status'],
        'geometryUnsupported' => ['fa' => 'پروفیل هندسی این نوع قطعه هنوز پشتیبانی نمی‌شود؛ مختصات یا محور حدس زده نمی‌شود.', 'en' => 'This component type has no supported geometry profile yet; coordinates and axes are not guessed.'],
        'elbowStandard' => ['fa' => 'استاندارد بُعدی زانویی', 'en' => 'Elbow dimensional standard'],
        'elbowStandardHelp' => ['fa' => 'B16.9 برای زانویی BW شعاع‌بلند؛ B16.11 برای زانویی فورج‌شده با دو سر SW یا دو سر رزوه‌ای. کلاس B16.11 کلید بُعدی است، نه کلاس PMS.', 'en' => 'B16.9 is the BW long-radius path; B16.11 is the forged path with two SW or two threaded ends. B16.11 class is a dimensional lookup key, not the project PMS class.'],
        'elbowQualifier' => ['fa' => 'گونه / زاویهٔ خم', 'en' => 'Variant / bend angle'],
        'elbowQualifierHelp' => ['fa' => 'B16.9: فقط 90LR یا 45LR. B16.11: فقط 90 یا 45 درجه؛ B16.11 عنوان LR/SR ندارد و با B16.9 alias نمی‌شود.', 'en' => 'B16.9: 90LR or 45LR only. B16.11: 90 or 45 degrees only; B16.11 does not name LR/SR elbows and is not aliased to B16.9.'],
        'elbowClass' => ['fa' => 'کلاس بُعدی B16.11', 'en' => 'B16.11 dimensional class'],
        'elbowClassHelp' => ['fa' => 'برای B16.11 اجباری و صریح؛ خالی برای B16.9. این مقدار از کلاس PMS یا pressure rating استنباط نمی‌شود.', 'en' => 'Required explicitly for B16.11; leave blank for B16.9. This is not inferred from PMS or treated as a direct pressure rating.'],
        'elbowEndType' => ['fa' => 'نوع سر هر دو انتها', 'en' => 'End type at both ends'],
        'elbowEndTypeHelp' => ['fa' => 'برای B16.11 صریح انتخاب کنید: SW یا SC. این پروفیل سرهای مختلط و street elbow را پوشش نمی‌دهد.', 'en' => 'Choose explicitly for B16.11: SW or SC. Mixed-end and street elbows are outside this profile.'],
        'elbowGeometryHelp' => ['fa' => 'این پروفیل هندسهٔ پورت است، نه مدل CAD. لنگر مرکز P1 است؛ +X از P1 به تقاطع مجازی امتداد محورهای مرکز پورت‌ها می‌رود؛ +Y سمت خم را تعیین می‌کند و رول صریحاً صفحهٔ خم را می‌چرخاند. مقدار center-to-end از ردیف B16.9-2018 Table 6.1-1 می‌آید؛ این فایل، تمپلت Autodesk Plant 3D نیست.', 'en' => 'This is a port-geometry profile, not a CAD solid. The anchor is P1 centre; local +X runs from P1 to the virtual intersection of the extended port centre-lines; +Y selects the bend side and explicit roll clocks the bend plane. Centre-to-end comes from B16.9-2018 Table 6.1-1; this is not an Autodesk Plant 3D template.'],
        'forgedElbowGeometryHelp' => ['fa' => 'این پروفیل فقط مختصات دو پورت است، نه CAD solid. B16.11-2021 فقط زاویه‌های 45/90 و ردیف‌های NPS/class مرجع را حل می‌کند. threaded از A (۹۰°) یا C (۴۵°)، مرکز تا انتها، استفاده می‌کند. برای SW، قرارداد برنامه datum پورت را کف سوکت می‌گیرد و A مرکز تا کف سوکت را مستقیم مصرف می‌کند؛ J عمق حداقل سوکت جداگانه ثبت می‌شود. فاصلهٔ تقریبی پیشنهادی پیش از جوش 1.5 mm اعمال نمی‌شود. هیچ شعاع LR/SR، انتهای مختلط، street elbow یا CAD solid استنباط نمی‌شود.', 'en' => 'This profile resolves two port coordinates only, not a CAD solid. B16.11-2021 resolves only 45/90-degree rows present for the exact NPS/class. Threaded uses table A (90°) or C (45°), centre-to-end. For SW, the application contract sets the port datum at the socket bottom and uses A directly; minimum socket depth J is recorded separately. The approximate recommended 1.5 mm pre-weld gap is not applied. No LR/SR radius, mixed ends, street elbow, or CAD solid is inferred.'],
        'geometryPoints' => ['fa' => 'مختصات پورت‌ها در فریم مدل (mm)', 'en' => 'Port coordinates in model frame (mm)'],
        'geometryPoint' => ['fa' => 'نقطه', 'en' => 'point'],
        'geometryAxis' => ['fa' => 'محور خروجی', 'en' => 'outward axis'],
        'frameRequired' => ['fa' => 'ابتدا فریم مدل پروژه را ثبت کنید.', 'en' => 'Define the project model frame before recording a pose.'],
        'poseDraftOnly' => ['fa' => 'ویرایش جهت‌گیری فقط در وضعیت DRAFT مجاز است.', 'en' => 'Pose editing is available only while the line is DRAFT.'],
        'jointGeometry' => ['fa' => 'تشخیص هندسی جوینت', 'en' => 'Joint geometry diagnostic'],
        'snapshotAction' => ['fa' => 'ثبت snapshot هندسهٔ جوینت‌ها', 'en' => 'Capture joint-geometry snapshots'],
        'snapshotHelp' => ['fa' => 'Snapshotها تغییرناپذیر و نسخه‌محورند؛ با تغییر هر ورودی STALE می‌شوند. ثبت نتیجه قاعدهٔ پذیرش یا PASS/FAIL ایجاد نمی‌کند.', 'en' => 'Snapshots are immutable and versioned; any changed input makes them STALE. Capturing a result does not create an acceptance rule or a PASS/FAIL verdict.'],
        'snapshotMissing' => ['fa' => 'هنوز snapshot ثبت نشده؛ مقدار زیر محاسبهٔ زنده و ذخیره‌نشده است.', 'en' => 'No snapshot yet; the value below is live and not persisted.'],
        'snapshotCurrent' => ['fa' => 'snapshot جاری، بازنگری', 'en' => 'Current snapshot revision'],
        'snapshotStale' => ['fa' => 'STALE — ورودی‌ها از زمان snapshot تغییر کرده‌اند؛ برای ثبت نتیجهٔ تازه snapshot بگیرید.', 'en' => 'STALE — inputs changed since capture; capture a new snapshot to record a current result.'],
        'gap' => ['fa' => 'فاصلهٔ خام', 'en' => 'raw gap'],
        'axisDeviation' => ['fa' => 'انحراف محور از جهت مخالف', 'en' => 'outward-axis deviation from opposition'],
        'unassessed' => ['fa' => 'UNASSESSED', 'en' => 'UNASSESSED'],
        'unassessedHelp' => ['fa' => 'مقدار خام هندسی است؛ قاعده/تلرانس پذیرش برای PASS/FAIL تعریف نشده است.', 'en' => 'Raw geometry only; no acceptance rule/tolerance is defined for PASS/FAIL.'],
        'checkStale' => ['fa' => 'STALE — هندسهٔ وابسته تا ثبت pose تازه قابل ارزیابی نیست.', 'en' => 'STALE — dependent geometry cannot be evaluated until a new pose is recorded.'],
        'checkUnresolved' => ['fa' => 'UNRESOLVED — دادهٔ هندسی کافی برای این جوینت وجود ندارد.', 'en' => 'UNRESOLVED — insufficient geometry is available for this joint.'],
        'seq'      => ['fa' => 'ردیف', 'en' => 'No.'],
        'type'     => ['fa' => 'نوع', 'en' => 'Type'],
        'size'     => ['fa' => 'سایز', 'en' => 'Size'],
        'ports'    => ['fa' => 'پورت‌ها', 'en' => 'Ports'],
        'length'   => ['fa' => 'طول', 'en' => 'Length'],
        'actions'  => ['fa' => 'اقدام', 'en' => 'Actions'],
        'remove'   => ['fa' => 'حذف', 'en' => 'Remove'],
        'none'     => ['fa' => 'هنوز چیزی روی این خط مدل نشده است. اولین قطعه را اضافه کنید.',
                       'en' => 'Nothing modelled on this line yet. Add the first component.'],
        'openends' => ['fa' => 'سرهای باز', 'en' => 'Open ends'],
        'complete' => ['fa' => 'سر بازی نمانده — این خط از نظر ساختاری کامل است.',
                       'en' => 'No open ends — this line is structurally complete.'],
        'attach'   => ['fa' => 'اتصال قطعه به این سر', 'en' => 'Attach a component to this end'],
        'attachbtn'=> ['fa' => 'اتصال', 'en' => 'Attach'],
        'lineowner'=> ['fa' => 'خط مالک رجیستر و مرجع PMS', 'en' => 'Joint register and PMS owner line'],
        'ownerBranch' => ['fa' => 'خط شاخه', 'en' => 'Branch line'],
        'ownerHeader' => ['fa' => 'خط هدر', 'en' => 'Header line'],
        'branchroot'=> ['fa' => 'ریشهٔ انشعاب', 'en' => 'Branch root'],
        'ownerHeaderLine' => ['fa' => 'خط هدرِ مرجع رجیستر/PMS برای جوینت پورت BRANCH سه‌راهی/چهارراهی', 'en' => 'Header line as register/PMS owner for TEE/CROSS branch-port weld'],
        'ownerHelp'=> ['fa' => 'برای جوینت‌های دیگرِ روی شاخه، ریشهٔ دیواره‌ای P2P/OLET را انتخاب کنید تا مالکیت HEADER به خط هدر ریشه متصل شود. قوانین PMS از کلاس خط مالک انتخاب‌شده گرفته می‌شوند: BRANCH یعنی کلاس خط شاخه و HEADER یعنی کلاس خط هدر.', 'en' => 'For other joints along a branch, select its P2P/OLET wall root to use the root header as owner. PMS rules come from the selected owner line: BRANCH uses the branch-line class; HEADER uses the header-line class.'],
        'ownerTeeCrossHelp' => ['fa' => 'برای جوینت پورت BRANCH سه‌راهی/چهارراهی، در حالت HEADER خط هدر را مستقیم انتخاب کنید؛ قوانین PMS از کلاس همان خط گرفته می‌شوند. در صورت انتخاب ریشهٔ P2P/OLET، خط انتخابی باید با هدر ریشه یکی باشد؛ با مالکیت BRANCH این فیلد را خالی بگذارید. پورت‌ها و اجزای فیزیکی جابه‌جا نمی‌شوند.', 'en' => 'For a TEE/CROSS BRANCH-port weld, select the header line when HEADER owns it; PMS rules then come from that line’s class. If you also select a P2P/OLET root, the selected line must match its header; leave this field blank when BRANCH owns the joint. Physical ports and components are not moved.'],
        'ownerScopeHelp' => ['fa' => 'انتخاب مالک دستی، هم رجیستر/شماره‌گذاری و هم کلاس PMS مرجع جوینت را تعیین می‌کند؛ هیچ مرزی در فلنج یا شیر حدس زده نمی‌شود.', 'en' => 'The manually selected owner determines both the register/number and governing PMS class; no boundary is inferred at a flange or valve.'],
        'jointPhysicalLine' => ['fa' => 'خط فیزیکی شاخه', 'en' => 'Physical branch line'],
        'wallFormTitle' => ['fa' => 'ایجاد جوینت انشعاب روی دیوارهٔ هدر', 'en' => 'Create a branch-to-header-wall joint'],
        'wallKind' => ['fa' => 'نوع اتصال دیواره‌ای', 'en' => 'Wall connection type'],
        'wallP2p' => ['fa' => 'انشعاب P2P', 'en' => 'P2P branch'],
        'wallOlet' => ['fa' => 'OLET', 'en' => 'OLET fitting'],
        'wallBranchPort' => ['fa' => 'سر باز لولهٔ انشعاب', 'en' => 'Open branch-pipe end'],
        'wallOletComponent' => ['fa' => 'قطعهٔ OLET روی خط انشعاب', 'en' => 'OLET component on this branch line'],
        'wallHeader' => ['fa' => 'لولهٔ هدر', 'en' => 'Header pipe'],
        'wallBranchKind' => ['fa' => 'روش اتصال P2P', 'en' => 'P2P branch form'],
        'wallStubIn' => ['fa' => 'استاب‌این', 'en' => 'Stub-in'],
        'wallStubOn' => ['fa' => 'استاب‌آن', 'en' => 'Stub-on'],
        'wallStation' => ['fa' => 'ایستگاه روی هدر (میلی‌متر)', 'en' => 'Station along header (mm)'],
        'wallOffset' => ['fa' => 'آفست از محور هدر (میلی‌متر)', 'en' => 'Offset from header centreline (mm)'],
        'wallBeta' => ['fa' => 'زاویهٔ β بین محور شاخه و هدر (درجه)', 'en' => 'β between branch and header axes (degrees)'],
        'wallBetaHelp' => ['fa' => 'برای P2P الزامی است؛ ۹۰° یعنی عمود. دامنهٔ ثبت ۴۵° تا ۹۰° است. برای OLET خالی بماند. β، θ یا چرخش component نیست.', 'en' => 'Required for P2P; 90° means perpendicular. Record 45°–90°. Leave blank for OLET. β does not encode θ or component rotation.'],
        'wallCreate' => ['fa' => 'ثبت جوینت دیواره‌ای', 'en' => 'Create wall joint'],
        'wallSelect' => ['fa' => '— انتخاب کنید —', 'en' => '— Select —'],
        'wallNoCandidates' => ['fa' => 'ابتدا یک سر باز لولهٔ انشعاب یا OLET با پورت RUN باز روی این خط مدل کنید.', 'en' => 'Place an open branch-pipe end or an OLET with an open RUN port on this line first.'],
        'wallNoHeader' => ['fa' => 'ابتدا لولهٔ هدر با سایز و طول مشخص مدل کنید.', 'en' => 'Place a header pipe with a known size and length first.'],
        'padTitle' => ['fa' => 'پد تقویتی P2P', 'en' => 'P2P reinforcement pad'],
        'padHelp' => ['fa' => 'فقط برای ریشهٔ مستقیم P2P (استاب‌این/استاب‌آن): ضخامت از PMS صادرشدهٔ خط هدر خوانده می‌شود و نصب، قطعهٔ فیزیکی را همراه دو جوش فیلتِ شماره‌دار جداگانه روی رجیستر هدر می‌سازد. جنس PAD اگر در value.pad_material قاعدهٔ PMS نیاید، از PIPE_MATERIAL هدر گرفته می‌شود؛ جنس تصریح‌شده در همان قاعده اعمال می‌شود. β باید صریحاً روی ریشه ثبت شده باشد؛ روش فعلی پروفیل زینی فقط برای β=۹۰° و آفست صفر معتبر است.', 'en' => 'Direct P2P stub-in/stub-on roots only: thickness comes from the header line’s issued PMS, and installation creates one physical pad plus two separately numbered fillet joints on the header register. If value.pad_material is absent from the PMS branch rule, PAD uses the header PIPE_MATERIAL; an explicitly declared PAD material is honored. β must be explicitly recorded on the root; the current saddle-perimeter method supports only β=90° and zero offset.'],
        'padMajorAxis' => ['fa' => 'محور بزرگ، روی محیط هدر', 'en' => 'Major axis, around header'],
        'padMinorAxis' => ['fa' => 'محور کوچک، در امتداد هدر', 'en' => 'Minor axis, along header'],
        'padMajorAxisShort' => ['fa' => 'محور بزرگ', 'en' => 'Major axis'],
        'padMinorAxisShort' => ['fa' => 'محور کوچک', 'en' => 'Minor axis'],
        'padZoneHalfWidth' => ['fa' => 'نیم‌عرض ناحیهٔ تقویت d₂', 'en' => 'Reinforcement-zone half-width d₂'],
        'padBeta' => ['fa' => 'زاویهٔ β', 'en' => 'Branch angle β'],
        'padGeometryNote' => ['fa' => 'این ابعاد کران‌های مرز بیرونیِ اسمی‌اند، نه حداقل کد یا نقشهٔ ساخت. منحنی تماس ایده‌آل دو استوانه و امتداد یکنواخت روی سطح بازشدهٔ هدر جداگانه ثبت می‌شوند؛ ضخامت دیواره، θ/چرخش، لقی Fit-up و تلرانس ساخت در پروفیل برش منظور نشده‌اند.', 'en' => 'These are bounds of the nominal outer perimeter—not code minimums or a fabrication drawing. The ideal cylinder-contact curve and uniform offset on the developed header surface are recorded separately; wall compensation, θ/rotation, fit-up clearance, and fabrication tolerance are not included in a cut profile.'],
        'pad3dTitle' => ['fa' => 'نمای سه‌بعدی شماتیک و ابعاد محاسبه‌شده', 'en' => '3D schematic and calculated dimensions'],
        'pad3dNote' => ['fa' => 'این نمایش، مرز بیرونی نمونه‌برداری‌شده را روی سطح هدر می‌پیچد؛ منحنی تماس استوانه‌ای و امتداد یکنواخت روی سطح بازشده جداگانه محاسبه و ذخیره می‌شوند. برش ضخامت‌دار شاخه، جبران دیواره، پخ/آماده‌سازی جوش، Fit-up و تلرانس ساخت مدل نشده‌اند؛ نقشهٔ ساخت نیست. فقط β=۹۰° و آفست صفر.', 'en' => 'This schematic wraps the sampled outer perimeter onto the header; the cylinder-contact curve and uniform developed-surface offset are calculated and stored separately. It does not model the branch wall cut, wall compensation, weld preparation, fit-up, or fabrication tolerances, and is not a fabrication drawing. Only β=90° and zero offset are supported.'],
        'pad3dControls' => ['fa' => 'برای چرخاندن نما بکشید؛ این فقط دوربین است و θ/چرخش قطعه نیست. با چرخ ماوس بزرگ‌نمایی کنید.', 'en' => 'Drag to rotate the camera only—not θ or part rotation. Use the mouse wheel to zoom.'],
        'pad3dReset' => ['fa' => 'بازنشانی نما', 'en' => 'Reset view'],
        'pad3dRotateLeft' => ['fa' => 'چرخش به چپ', 'en' => 'Rotate left'],
        'pad3dRotateRight' => ['fa' => 'چرخش به راست', 'en' => 'Rotate right'],
        'pad3dCanvasFallback' => ['fa' => 'نمای سه‌بعدی شماتیک PAD؛ ابعاد در کنار شکل درج شده‌اند.', 'en' => '3D PAD schematic; calculated dimensions are listed beside the view.'],
        'pad3dRenderError' => ['fa' => 'نمای سه‌بعدی برای این snapshot قابل نمایش نیست؛ مقادیر ذخیره‌شده همچنان مرجع هستند.', 'en' => 'The 3D view is unavailable for this snapshot; the saved dimensions remain authoritative.'],
        'pad3dSpanError' => ['fa' => 'پوش محاسبه‌شده تقریباً از یک دور کامل هدر بزرگ‌تر است؛ برای جلوگیری از نمایش گمراه‌کننده، مدل سه‌بعدی رسم نشد.', 'en' => 'The calculated envelope is approximately larger than one full header circumference, so the 3D schematic was withheld to avoid a misleading wrap.'],
        'pad3dHeaderOD' => ['fa' => 'قطر بیرونی هدر', 'en' => 'Header OD'],
        'pad3dBranchOD' => ['fa' => 'قطر بیرونی شاخه', 'en' => 'Branch OD'],
        'pad3dSchedule' => ['fa' => 'Schedule', 'en' => 'Schedule'],
        'pad3dCa' => ['fa' => 'CA حاکم', 'en' => 'Governing CA'],
        'pad3dOffset' => ['fa' => 'آفست', 'en' => 'Offset'],
        'padDirectionAround' => ['fa' => 'در راستای محیط هدر', 'en' => 'around header'],
        'padDirectionAlong' => ['fa' => 'در امتداد هدر', 'en' => 'along header'],
        'padRoot' => ['fa' => 'ریشهٔ P2P', 'en' => 'P2P wall root'],
        'padInstall' => ['fa' => 'نصب پد و دو جوش فیلت', 'en' => 'Install pad and two fillets'],
        'padNone' => ['fa' => 'ریشهٔ مستقیم P2P بدون پد که PMS صادرشدهٔ خط هدر برای آن نصب پد را الزامی کند موجود نیست.', 'en' => 'No uncovered direct P2P root currently requires a pad under its header’s issued PMS rule.'],
        'padList' => ['fa' => 'پدهای تقویتی نصب‌شده', 'en' => 'Installed reinforcement pads'],
        'padThickness' => ['fa' => 'ضخامت پد', 'en' => 'Pad thickness'],
        'padMaterial' => ['fa' => 'جنس PAD', 'en' => 'PAD material'],
        'padMaterialDefault' => ['fa' => 'پیش‌فرض از جنس هدر', 'en' => 'defaulted from header'],
        'padMaterialPms' => ['fa' => 'طبق قاعدهٔ PMS', 'en' => 'as declared in PMS'],
        'padStressRatio' => ['fa' => 'نسبت تنش PAD به هدر', 'en' => 'PAD/run stress ratio'],
        'padHeaderJoint' => ['fa' => 'جوش پد به هدر', 'en' => 'Pad-to-header fillet'],
        'padBranchJoint' => ['fa' => 'جوش پد به لولهٔ شاخه', 'en' => 'Pad-to-branch-pipe fillet'],
        'padWeldRequirements' => ['fa' => 'حداقل‌های دو جوش فیلت PAD', 'en' => 'PAD fillet-weld minimums'],
        'padWeldThroat' => ['fa' => 'حداقل گلویی طراحی', 'en' => 'Minimum design throat'],
        'padWeldEqualLeg' => ['fa' => 'معادل نظری اندازهٔ پای مساوی', 'en' => 'Theoretical equal-leg equivalent'],
        'padWeldBasis' => ['fa' => 'این مقادیر حداقل گلویی طراحی طبق B31.3-2024 هستند؛ معادل پای مساوی فقط نظری است. اندازهٔ واقعی جوش و پذیرش بازرسی در اینجا ثبت یا تأیید نمی‌شود.', 'en' => 'These are B31.3-2024 minimum design throats; equal-leg values are theoretical equivalents. Actual as-welded size and inspection acceptance are not recorded or verified here.'],
        'padOwner' => ['fa' => 'مالک رجیستر/PMS', 'en' => 'Register/PMS owner'],
        'padWeldMetrics' => ['fa' => 'جوش فیلت پد، شماره‌گذاری‌شده روی این خط', 'en' => 'Pad fillet welds registered on this line'],
        'padPhysicalElsewhere' => ['fa' => 'جوش فیلت پدِ فیزیکی این خط که در خط دیگری شماره‌گذاری شده', 'en' => 'Pad fillets physically on this line but registered elsewhere'],
        'padSideHeader' => ['fa' => 'سمت هدر', 'en' => 'header side'],
        'padSideBranch' => ['fa' => 'سمت شاخه', 'en' => 'branch side'],
        'padFilletTo' => ['fa' => 'فیلت به', 'en' => 'fillet to'],
        'padRuleRequired' => ['fa' => 'نصب پد فقط وقتی مجاز است که قاعدهٔ صادرشدهٔ PMS هدر برای این سایز، پد را الزام کند.', 'en' => 'A pad can be installed only when the header’s issued PMS rule requires one for this size pair.'],
        'add'      => ['fa' => 'افزودن قطعهٔ مستقل', 'en' => 'Add a standalone component'],
        'addbtn'   => ['fa' => 'افزودن', 'en' => 'Add'],
        'branch'   => ['fa' => 'سایز انشعاب', 'en' => 'Branch size'],
        'small'    => ['fa' => 'سر کوچک', 'en' => 'Small end'],
        'lenmm'    => ['fa' => 'طول (میلی‌متر)', 'en' => 'Length (mm)'],
        'lengthGeometryHelp' => ['fa' => 'فقط برای پروفیل هندسی: طول PIPE همان طول برش ثبت‌شده است؛ طول NIPPLE فاصلهٔ انتها تا انتها میان صفحه‌های مرکزی پورت است. این فیلد برای ELBOW به‌کار نمی‌رود؛ center-to-end فقط از مرجع B16.9 و qualifier صریح خوانده می‌شود.', 'en' => 'For geometry profiles only: PIPE length is its entered cut length; NIPPLE length is end-to-end between port centre planes. This field does not define an ELBOW; centre-to-end is read from the B16.9 reference row selected by its explicit qualifier.'],
        'sched'    => ['fa' => 'رده', 'en' => 'Schedule'],
        'issuedPms'=> ['fa' => 'بازنگری PMS زمان صدور', 'en' => 'Issued PMS revision'],
        'unmapped' => ['fa' => 'در انتظار بررسی کلاس و بازنگری PMS', 'en' => 'Class/PMS revision review required'],
        'scheduleMatches' => ['fa' => 'منطبق با ردهٔ PMS زمان صدور', 'en' => 'Matches issued PMS schedule'],
        'scheduleDiffers' => ['fa' => 'مغایر با ردهٔ PMS زمان صدور', 'en' => 'Differs from issued PMS schedule'],
        'scheduleUnknown' => ['fa' => 'ردهٔ PMS قابل بررسی نیست', 'en' => 'Issued PMS schedule cannot be checked'],
        'expected' => ['fa' => 'ردهٔ مورد انتظار', 'en' => 'Expected'],
        'requestReview' => ['fa' => 'درخواست بررسی مغایرت', 'en' => 'Request discrepancy review'],
        'requestReason' => ['fa' => 'دلیل و شرح مغایرت (تأیید نیست)', 'en' => 'Reason for discrepancy (not approval)'],
        'reviewPending' => ['fa' => 'درخواست بررسی ثبت شده؛ هنوز تأیید نشده است', 'en' => 'Review requested; not approved'],
        'taperProposal' => ['fa' => 'Taper داخلیِ پیشنهادی (صرفاً طرح؛ تأیید Fit-up نیست)', 'en' => 'Proposed internal taper (plan only; not Fit-up acceptance)'],
        'remedyUndetermined' => ['fa' => 'روش رفع هنوز تعیین نشده است', 'en' => 'Remedy not yet determined'],
        'requestHistory' => ['fa' => 'سابقهٔ درخواست‌های مغایرت', 'en' => 'Discrepancy request history'],
        'previousValue' => ['fa' => 'مقدار پیشین؛ برای ردهٔ فعلی معتبر نیست', 'en' => 'Previous value; not applicable to current schedule'],
        'unverifiedPmsClaim' => ['fa' => 'مقدار PMS این درخواست با مدرک حاکم فعلی قابل تطبیق نیست؛ مبنای پیش‌نویس جدید نیست', 'en' => 'Request PMS value does not match the current issued basis; not eligible for a new draft'],
        'reviewRecorded' => ['fa' => 'رویداد بررسی ثبت شده؛ تأیید مهندسی احراز نشده', 'en' => 'Review event recorded; engineering acceptance not established'],
        'internalDraft' => ['fa' => 'پیش‌نویس داخلی؛ صادر نشده', 'en' => 'Internal draft; not issued'],
        'externalMetadata' => ['fa' => 'اطلاعات گزارش بیرونی؛ راستی‌آزمایی نشده', 'en' => 'External report metadata; not verified'],
        'captureDraft' => ['fa' => 'ساخت پیش‌نویس داخلی TQ/NCR (بدون صدور)', 'en' => 'Create internal TQ/NCR draft (not issued)'],
        'captureExternal' => ['fa' => 'ثبت اطلاعات TQ/NCR صادرشده در خارج (بدون تأیید)', 'en' => 'Record externally issued TQ/NCR metadata (not verified)'],
        'reportNumber' => ['fa' => 'شمارهٔ گزارش بیرونی', 'en' => 'External report number'],
        'reportRevision' => ['fa' => 'بازنگری (اختیاری)', 'en' => 'Revision (optional)'],
        'reportIssuer' => ['fa' => 'صادرکنندهٔ بیرونی', 'en' => 'External issuer'],
        'reportSigner' => ['fa' => 'نام امضاکنندهٔ بیرونی', 'en' => 'External signatory'],
        'reportCapacity' => ['fa' => 'سمت امضاکننده', 'en' => 'Signatory capacity'],
        'reportIssuedAt' => ['fa' => 'تاریخ صدور بیرونی', 'en' => 'External issue date'],
        'reportSignedAt' => ['fa' => 'تاریخ امضا', 'en' => 'Signature date'],
        'reportRegister' => ['fa' => 'مرجع رجیستر/بایگانی بیرونی', 'en' => 'External register reference'],
        'reportDetails' => ['fa' => 'جزئیات اطلاعات ثبت‌شده (ادعای بیرونی؛ راستی‌آزمایی نشده)', 'en' => 'Recorded details (external claim; not verified)'],
        'reportRecordedAt' => ['fa' => 'زمان ثبت در سامانه', 'en' => 'Recorded in system at'],
        'reportRecorder' => ['fa' => 'شناسهٔ ثبت‌کننده در سامانه (نه صادرکننده)', 'en' => 'System recorder ID (not issuer)'],
        'inherit'  => ['fa' => 'سایز از پورت والد به ارث می‌رسد و قابل تایپ نیست.',
                       'en' => 'The size is inherited from the parent port and cannot be typed.'],
        'locked'   => ['fa' => 'این خط ابلاغ شده است؛ تغییر مدل آن نیاز به بازگشت به پیش‌نویس دارد.',
                       'en' => 'This line is released; changing its model needs a revert to draft first.'],
        'portsnote'=> ['fa' => 'تعداد پورت‌ها از نوع قطعه می‌آید — سه‌راه همیشه سه پورت دارد و کسی آن را وارد نمی‌کند.',
                       'en' => 'The port count comes from the component type — a tee always has three, and nobody types that.'],
        'joints'   => ['fa' => 'اتصالات', 'en' => 'Joints'],
        'jnone'    => ['fa' => 'هنوز اتصالی روی این خط ثبت نشده است.', 'en' => 'No joints on this line yet.'],
        'jnum'     => ['fa' => 'شماره', 'en' => 'No.'],
        'jtype'    => ['fa' => 'نوع', 'en' => 'Type'],
        'jbetween' => ['fa' => 'بین', 'en' => 'Between'],
        'wall'     => ['fa' => 'دیواره', 'en' => 'wall'],
        'station'  => ['fa' => 'ایستگاه', 'en' => 'station'],
        'jstate'   => ['fa' => 'وضعیت', 'en' => 'State'],
        'jdia'     => ['fa' => 'قطر-اینچ', 'en' => 'Dia-inch'],
        'junnum'   => ['fa' => 'بدون شماره', 'en' => 'not numbered'],
        'jwelds'   => ['fa' => 'جوش', 'en' => 'welds'],
        'jfitted'  => ['fa' => 'مونتاژشده', 'en' => 'fitted'],
        'jdianote' => ['fa' => 'قطر-اینچ همیشه از سر کوچک‌تر گرفته می‌شود؛ ذخیره نمی‌شود و هر بار محاسبه می‌گردد.',
                       'en' => 'Dia-inch always takes the smaller end; it is computed, never stored.'],
    ];
    $L = fn (string $k): string => $t[$k][$lang] ?? $t[$k]['en'];
    $num = fn ($v): string => \App\Support\JalaliDate::digits((string) $v, $lang);
    $formatMm = fn ($value): string => $num(rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.'));

    // Inch marks inside an <option>: no markup is allowed there, so <bdi> is
    // out. A trailing LRM pins the quote to its number rather than letting
    // the bidi algorithm float it to the visual start of the line.
    $inch = fn ($v): string => \App\Support\JalaliDate::digits((string) $v, $lang).'"'.($lang === 'fa' ? "\u{200E}" : '');

    $tplName = function (string $code) use ($templates, $lang): string {
        foreach ($templates as $tpl) {
            if ($tpl['code'] === $code) {
                return $tpl['name'][$lang] ?? $tpl['name']['en'] ?? $code;
            }
        }
        return $code;
    };

    $q = ['lang' => $lang] + (request()->has('sid') ? ['sid' => request()->query('sid')] : []);
    $base = "/projects/{$project->code}/piping/{$line->id}/model?".http_build_query($q);
@endphp
<!doctype html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $line->line_number }} — {{ $L('title') }}</title>
@include('partials.shell')
@include('partials.tabletools')
<script src="{{ asset('js/branch-pad-3d.js') }}" defer></script>
</head>
<body>
<div class="app">
@include('partials.sidebar')
<div class="wrap">
@include('partials.topbar')

  <h1>{{ $L('title') }}</h1>
  <div class="sub">
    <span class="mono">{{ $line->line_number }}</span>
    · {{ $line->subsystem?->code }}
    @if($line->nominal_size) · <bdi>{{ $num($line->nominal_size) }}"</bdi> @endif
    @if($line->pipingClass) · {{ $line->pipingClass->code }} @endif
  </div>
  @if($line->pms_class_snapshot_id !== null)
    @php $issued = $line->pmsSnapshot?->documentRevision; @endphp
    <div style="font-size:12px;color:var(--muted);margin-top:5px;">
      {{ $L('issuedPms') }}:
      {{ $issued?->document?->number ?? 'PMS' }} · {{ $issued?->revision ?? '—' }}
      @if($issued?->suitability) ({{ $issued->suitability }}) @endif
    </div>
  @else
    <div style="font-size:12px;color:#fca5a5;margin-top:5px;">{{ $L('unmapped') }}</div>
  @endif
  <p style="color:var(--muted);font-size:13px;line-height:1.8;margin:6px 0 0;">{{ $L('sub') }}</p>
  <p style="margin:10px 0 0;">
    <a href="/projects/{{ $project->code }}/piping?{{ http_build_query($q) }}"
       style="color:var(--accent,#60a5fa);font-size:13px;">&larr; {{ $L('back') }}</a>
  </p>

  @if(! $editable)
    <div style="margin-top:14px;padding:10px 12px;border-radius:8px;border:1px solid #92400e;background:rgba(120,53,15,.25);color:#fcd34d;font-size:13px;line-height:1.7;">
      {{ $L('locked') }}
    </div>
  @endif

  @if(session('status'))
    <div role="status" style="margin-top:14px;padding:10px 12px;border-radius:8px;border:1px solid #14532d;background:rgba(20,83,45,.25);color:#86efac;font-size:13px;line-height:1.7;">
      {{ session('status') }}
    </div>
  @endif
  @if($errors->any())
    <div role="alert" style="margin-top:14px;padding:10px 12px;border-radius:8px;border:1px solid #7f1d1d;background:rgba(127,29,29,.25);color:#fca5a5;font-size:13px;line-height:1.7;">
      @foreach($errors->all() as $message)<div>{{ $message }}</div>@endforeach
    </div>
  @endif

  @include('piping.partials.model-frame')

  {{-- ------------------------------------------------ open ends --------- --}}
  <h2 style="margin-top:26px;font-size:16px;">{{ $L('openends') }}
    <span style="color:var(--muted);font-weight:400;font-size:13px;">({{ $num(count($open)) }})</span>
  </h2>

  @if(count($open) === 0)
    <div style="margin-top:10px;padding:10px 12px;border-radius:8px;border:1px solid #14532d;background:rgba(20,83,45,.25);color:#86efac;font-size:13px;">
      {{ $L('complete') }}
    </div>
  @else
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px;margin-top:10px;">
      @foreach($open as $port)
        @php
          $isTeeCrossBranchPort = $port->role === 'BRANCH'
              && in_array($port->component?->template_code, ['TEE', 'TEE-SKEWED', 'CROSS'], true);
        @endphp
        <div style="border:1px solid var(--line);border-radius:10px;background:var(--card);padding:10px 12px;">
          <div class="mono" style="font-size:13px;"><bdi>{{ $port->component->label() }}</bdi></div>
          <div style="color:var(--muted);font-size:12px;margin-top:2px;"><bdi>{{ $port->label() }}</bdi>
            @if($port->end_type) · {{ $port->end_type }} @endif
          </div>

          @if($editable)
          <form method="POST" action="/projects/{{ $project->code }}/piping/{{ $line->id }}/model/attach?{{ http_build_query($q) }}"
                style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;">
            @csrf
            <input type="hidden" name="port_id" value="{{ $port->id }}">
            <select name="template_code" required
                    style="flex:1 1 60%;min-width:0;padding:5px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;">
              @foreach($templates as $tpl)
                <option value="{{ $tpl['code'] }}">{{ $tpl['name'][$lang] ?? $tpl['name']['en'] }} ({{ $num($tpl['ports']) }})</option>
              @endforeach
            </select>
            <label style="flex:1 1 100%;min-width:160px;color:var(--muted);font-size:11px;">
              {{ $L('elbowStandard') }}
              <select name="fitting_standard" aria-label="{{ $L('elbowStandard') }}"
                      style="width:100%;margin-top:3px;padding:5px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;">
                <option value="">—</option>
                <option value="B16.9" @selected(old('fitting_standard') === 'B16.9')>ASME B16.9-2018 · BW LR</option>
                <option value="B16.11" @selected(old('fitting_standard') === 'B16.11')>ASME B16.11-2021 · forged</option>
              </select>
              <small style="display:block;margin-top:3px;line-height:1.5;">{{ $L('elbowStandardHelp') }}</small>
            </label>
            <label style="flex:1 1 100%;min-width:160px;color:var(--muted);font-size:11px;">
              {{ $L('elbowQualifier') }}
              <select name="fitting_qualifier" aria-label="{{ $L('elbowQualifier') }}"
                      style="width:100%;margin-top:3px;padding:5px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;">
                <option value="">—</option>
                <optgroup label="B16.9 · BW long radius">
                  <option value="90LR" @selected(old('fitting_qualifier') === '90LR')>90° LR</option>
                  <option value="45LR" @selected(old('fitting_qualifier') === '45LR')>45° LR</option>
                </optgroup>
                <optgroup label="B16.11 · angle only">
                  <option value="90" @selected(old('fitting_qualifier') === '90')>90°</option>
                  <option value="45" @selected(old('fitting_qualifier') === '45')>45°</option>
                </optgroup>
              </select>
              <small style="display:block;margin-top:3px;line-height:1.5;">{{ $L('elbowQualifierHelp') }}</small>
            </label>
            <label style="flex:1 1 48%;min-width:140px;color:var(--muted);font-size:11px;">
              {{ $L('elbowClass') }}
              <select name="fitting_class_designation" aria-label="{{ $L('elbowClass') }}"
                      style="width:100%;margin-top:3px;padding:5px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;">
                <option value="">—</option>
                @foreach([2000, 3000, 6000, 9000] as $classDesignation)
                  <option value="{{ $classDesignation }}" @selected((string) old('fitting_class_designation') === (string) $classDesignation)>{{ $classDesignation }}</option>
                @endforeach
              </select>
              <small style="display:block;margin-top:3px;line-height:1.5;">{{ $L('elbowClassHelp') }}</small>
            </label>
            <label style="flex:1 1 48%;min-width:140px;color:var(--muted);font-size:11px;">
              {{ $L('elbowEndType') }}
              <select name="fitting_end_type" aria-label="{{ $L('elbowEndType') }}"
                      style="width:100%;margin-top:3px;padding:5px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;">
                <option value="">—</option>
                <option value="BW" @selected(old('fitting_end_type') === 'BW')>BW</option>
                <option value="SW" @selected(old('fitting_end_type') === 'SW')>SW</option>
                <option value="SC" @selected(old('fitting_end_type') === 'SC')>SC · threaded</option>
              </select>
              <small style="display:block;margin-top:3px;line-height:1.5;">{{ $L('elbowEndTypeHelp') }}</small>
            </label>
            @if($branchRoots->isNotEmpty() || $isTeeCrossBranchPort)
              <label style="flex:1 1 45%;min-width:140px;color:var(--muted);font-size:11px;">
                {{ $L('lineowner') }}
                <select name="line_owner"
                        style="width:100%;margin-top:3px;padding:5px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;">
                  <option value="BRANCH" @selected(old('line_owner', 'BRANCH') === 'BRANCH')>{{ $L('ownerBranch') }}</option>
                  <option value="HEADER" @selected(old('line_owner') === 'HEADER')>{{ $L('ownerHeader') }}</option>
                </select>
              </label>
              @if($branchRoots->isNotEmpty())
                <label style="flex:1 1 45%;min-width:140px;color:var(--muted);font-size:11px;">
                  {{ $L('branchroot') }}
                  <select name="branch_root_joint_id"
                          style="width:100%;margin-top:3px;padding:5px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;">
                    <option value="">—</option>
                    @foreach($branchRoots as $root)
                      <option value="{{ $root->id }}" @selected(old('branch_root_joint_id') === $root->id)>
                        {{ $root->branchHeaderComponent?->line?->line_number ?? '?' }} · {{ $root->joint_type }} · {{ $num($root->displayNumber() ?? '—') }}
                      </option>
                    @endforeach
                  </select>
                </label>
              @endif
              @if($isTeeCrossBranchPort)
                <label style="flex:1 1 100%;min-width:140px;color:var(--muted);font-size:11px;">
                  {{ $L('ownerHeaderLine') }}
                  <select name="header_line_id"
                          style="width:100%;margin-top:3px;padding:5px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;">
                    <option value="">—</option>
                    @foreach($ownerLines as $candidateLine)
                      <option value="{{ $candidateLine->id }}" @selected(old('header_line_id') === $candidateLine->id)>
                        {{ $candidateLine->line_number }}{{ $candidateLine->subsystem?->code ? ' · '.$candidateLine->subsystem->code : '' }}
                      </option>
                    @endforeach
                  </select>
                </label>
              @endif
              @if($branchRoots->isNotEmpty())
                <small style="flex:1 1 100%;color:var(--muted);font-size:10px;">{{ $L('ownerHelp') }}</small>
              @endif
              @if($isTeeCrossBranchPort)
                <small style="flex:1 1 100%;color:var(--muted);font-size:10px;">{{ $L('ownerTeeCrossHelp') }}</small>
              @endif
              <small style="flex:1 1 100%;color:var(--muted);font-size:10px;">{{ $L('ownerScopeHelp') }}</small>
            @endif
            <button type="submit"
                    style="padding:5px 12px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;cursor:pointer;">
              {{ $L('attachbtn') }}
            </button>
          </form>
          @endif
        </div>
      @endforeach
    </div>
    <p style="color:var(--muted);font-size:11px;margin-top:8px;">{{ $L('inherit') }}</p>
  @endif

  @if($editable)
    <h2 style="margin-top:28px;font-size:16px;">{{ $L('wallFormTitle') }}</h2>
    @if($wallHeaders->isEmpty())
      <p style="color:var(--muted);font-size:12px;margin-top:8px;">{{ $L('wallNoHeader') }}</p>
    @elseif($wallBranchPorts->isEmpty() && $wallOlets->isEmpty())
      <p style="color:var(--muted);font-size:12px;margin-top:8px;">{{ $L('wallNoCandidates') }}</p>
    @else
      @php $defaultWallKind = $wallBranchPorts->isNotEmpty() ? 'P2P' : 'OLET'; @endphp
      <form method="POST" action="/projects/{{ $project->code }}/piping/{{ $line->id }}/model/wall?{{ http_build_query($q) }}"
            style="margin-top:10px;padding:12px;border:1px solid var(--line);border-radius:10px;background:var(--card);display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:9px;">
        @csrf
        <label style="color:var(--muted);font-size:11px;">{{ $L('wallKind') }}
          <select name="wall_kind" required style="width:100%;margin-top:3px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;">
            <option value="P2P" @selected(old('wall_kind', $defaultWallKind) === 'P2P') @disabled($wallBranchPorts->isEmpty())>{{ $L('wallP2p') }}</option>
            <option value="OLET" @selected(old('wall_kind', $defaultWallKind) === 'OLET') @disabled($wallOlets->isEmpty())>{{ $L('wallOlet') }}</option>
          </select>
        </label>
        <label style="color:var(--muted);font-size:11px;">{{ $L('wallBranchPort') }}
          <select name="branch_port_id" style="width:100%;margin-top:3px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;">
            <option value="">{{ $L('wallSelect') }}</option>
            @foreach($wallBranchPorts as $port)
              <option value="{{ $port->id }}" @selected(old('branch_port_id') === $port->id)>
                {{ $port->component?->label() }} · {{ $port->label() }}
              </option>
            @endforeach
          </select>
        </label>
        <label style="color:var(--muted);font-size:11px;">{{ $L('wallOletComponent') }}
          <select name="olet_component_id" style="width:100%;margin-top:3px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;">
            <option value="">{{ $L('wallSelect') }}</option>
            @foreach($wallOlets as $olet)
              <option value="{{ $olet->id }}" @selected(old('olet_component_id') === $olet->id)>{{ $olet->label() }}</option>
            @endforeach
          </select>
        </label>
        <label style="color:var(--muted);font-size:11px;">{{ $L('wallHeader') }}
          <select name="header_component_id" required style="width:100%;margin-top:3px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;">
            <option value="">{{ $L('wallSelect') }}</option>
            @foreach($wallHeaders as $header)
              <option value="{{ $header->id }}" @selected(old('header_component_id') === $header->id)>
                {{ $header->line?->line_number ?? '?' }} · {{ $header->label() }} ({{ $num($header->length_mm) }} mm)
              </option>
            @endforeach
          </select>
        </label>
        <label style="color:var(--muted);font-size:11px;">{{ $L('wallBranchKind') }}
          <select name="branch_kind" style="width:100%;margin-top:3px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;">
            <option value="STUB_IN" @selected(old('branch_kind', 'STUB_IN') === 'STUB_IN')>{{ $L('wallStubIn') }}</option>
            <option value="STUB_ON" @selected(old('branch_kind') === 'STUB_ON')>{{ $L('wallStubOn') }}</option>
          </select>
        </label>
        <label style="color:var(--muted);font-size:11px;">{{ $L('lineowner') }}
          <select name="line_owner" required style="width:100%;margin-top:3px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;">
            <option value="BRANCH" @selected(old('line_owner', 'BRANCH') === 'BRANCH')>{{ $L('ownerBranch') }}</option>
            <option value="HEADER" @selected(old('line_owner') === 'HEADER')>{{ $L('ownerHeader') }}</option>
          </select>
        </label>
        <label style="color:var(--muted);font-size:11px;">{{ $L('wallStation') }}
          <input type="number" name="station_mm" min="0" step="any" value="{{ old('station_mm') }}" required style="width:100%;box-sizing:border-box;margin-top:3px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;">
        </label>
        <label style="color:var(--muted);font-size:11px;">{{ $L('wallOffset') }}
          <input type="number" name="offset_mm" step="any" value="{{ old('offset_mm', '0') }}" style="width:100%;box-sizing:border-box;margin-top:3px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;">
        </label>
        <label style="color:var(--muted);font-size:11px;">{{ $L('wallBeta') }}
          <input type="number" name="branch_longitudinal_angle_deg" min="45" max="90" step="0.01" value="{{ old('branch_longitudinal_angle_deg') }}" style="width:100%;box-sizing:border-box;margin-top:3px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;">
        </label>
        <small style="grid-column:1/-1;color:var(--muted);font-size:10px;">{{ $L('wallBetaHelp') }}</small>
        <button type="submit" style="align-self:end;padding:7px 12px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;cursor:pointer;">
          {{ $L('wallCreate') }}
        </button>
        <small style="grid-column:1/-1;color:var(--muted);font-size:10px;">{{ $L('ownerScopeHelp') }}</small>
      </form>
    @endif
  @endif

  @if($editable && $branchPadRoots->isNotEmpty())
    <h2 style="margin-top:24px;font-size:15px;">{{ $L('padTitle') }}</h2>
    <p style="color:var(--muted);font-size:11px;line-height:1.7;margin:5px 0;">{{ $L('padHelp') }}</p>
    <form method="POST" action="/projects/{{ $project->code }}/piping/{{ $line->id }}/model/pads?{{ http_build_query($q) }}"
          style="margin-top:8px;padding:10px;border:1px solid var(--line);border-radius:8px;background:var(--card);display:flex;gap:8px;align-items:end;flex-wrap:wrap;">
      @csrf
      <label style="flex:1 1 260px;color:var(--muted);font-size:11px;">{{ $L('padRoot') }}
        <select name="branch_root_joint_id" required style="width:100%;margin-top:3px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;">
          <option value="">{{ $L('wallSelect') }}</option>
          @foreach($branchPadRoots as $root)
            <option value="{{ $root->id }}" @selected(old('branch_root_joint_id') === $root->id)>
              {{ $root->branchHeaderComponent?->line?->line_number ?? '?' }} · {{ $root->joint_type }} · {{ $num($root->displayNumber() ?? '—') }}
            </option>
          @endforeach
        </select>
      </label>
      <button type="submit" style="padding:6px 12px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;font-size:12px;cursor:pointer;">{{ $L('padInstall') }}</button>
      <small style="flex:1 1 100%;color:var(--muted);font-size:10px;">{{ $L('padRuleRequired') }}</small>
    </form>
  @elseif($editable && $branchRoots->contains(fn ($root) => in_array($root->joint_type, ['BRANCH_STUB_IN', 'BRANCH_STUB_ON'], true)))
    <h2 style="margin-top:24px;font-size:15px;">{{ $L('padTitle') }}</h2>
    <p style="color:var(--muted);font-size:11px;">{{ $L('padNone') }}</p>
  @endif

  @if($branchPads->isNotEmpty())
    <h3 style="margin-top:20px;font-size:14px;">{{ $L('padList') }}</h3>
    <div style="display:grid;gap:8px;margin-top:8px;">
      @foreach($branchPads as $pad)
        <div style="padding:10px 12px;border:1px solid var(--line);border-radius:8px;background:var(--card);font-size:12px;">
          <strong><bdi>{{ $pad->component?->label() }}</bdi></strong>
          <span style="color:var(--muted);">· {{ $L('padThickness') }} <bdi>{{ $num($pad->pad_thickness_mm) }} mm</bdi></span>
          <span style="color:var(--muted);">· {{ $L('padOwner') }} <bdi>{{ $pad->headerLine?->line_number ?? '—' }}</bdi></span>
          <div style="margin-top:5px;color:var(--muted);">
            {{ $L('padMajorAxis') }}: <bdi>{{ $num($pad->pad_major_axis_mm) }} mm</bdi>
            <span style="color:var(--line);">|</span>
            {{ $L('padMinorAxis') }}: <bdi>{{ $num($pad->pad_minor_axis_mm) }} mm</bdi>
            <span style="color:var(--line);">|</span>
            {{ $L('padZoneHalfWidth') }}: <bdi>{{ $num($pad->pad_geometry_d2_mm) }} mm</bdi>
            <span style="color:var(--line);">|</span>
            {{ $L('padBeta') }}: <bdi>{{ $num($pad->pad_geometry_beta_deg) }}°</bdi>
          </div>
          <div style="margin-top:4px;color:var(--muted);font-size:11px;line-height:1.6;">{{ $L('padGeometryNote') }}</div>
          @include('piping.partials.branch-pad-3d', ['pad' => $pad])
          <div style="margin-top:5px;color:var(--muted);">
            {{ $L('padHeaderJoint') }}: <bdi>{{ $num($pad->headerJoint?->displayNumber() ?? '—') }}</bdi>
            <span style="color:var(--line);">|</span>
            {{ $L('padBranchJoint') }}: <bdi>{{ $num($pad->branchJoint?->displayNumber() ?? '—') }}</bdi>
          </div>
          @include('piping.partials.branch-pad-weld-requirements', ['pad' => $pad])
        </div>
      @endforeach
    </div>
  @endif

  {{-- ------------------------------------------------ components -------- --}}
  <h2 style="margin-top:28px;font-size:16px;">{{ $L('title') }}
    <span style="font-weight:400;font-size:11.5px;color:var(--muted);margin-inline-start:10px;">
      <span style="color:#86efac;">●</span> {{ $fa ? 'متصل' : 'connected' }}
      &nbsp;<span style="color:#fcd34d;">○</span> {{ $fa ? 'باز' : 'open' }}
    </span>
  </h2>

  <table data-tt style="margin-top:10px;">
    <thead>
      <tr>
        <th data-sort="number">{{ $L('seq') }}</th>
        <th data-sort="text" data-facet>{{ $L('type') }}</th>
        <th data-sort="text">{{ $L('size') }}</th>
        <th data-sort="text">{{ $L('sched') }}</th>
        <th>{{ $L('ports') }}</th>
        <th data-sort="number">{{ $L('length') }}</th>
        <th>{{ $L('geometry') }}</th>
        <th>{{ $L('actions') }}</th>
      </tr>
    </thead>
    <tbody>
    @forelse($rows as $c)
      <tr>
        <td class="mono">{{ $num($c->sequence) }}</td>
        <td>{{ $tplName($c->template_code) }}
          <div style="color:var(--muted);font-size:11px;" class="mono">
            {{ $c->template_code }}
            @if($c->fitting_standard === 'B16.11')
              · B16.11-2021 · {{ $c->fitting_qualifier }}° · Class {{ $c->fitting_class_designation }} · {{ $c->ports->first()?->end_type }} both ends
            @elseif($c->fitting_qualifier)
              · {{ $c->fitting_standard === 'B16.9' ? 'B16.9-2018 · ' : '' }}{{ $c->fitting_qualifier }}
            @endif
          </div>
        </td>
        <td class="mono">@if($c->nominal_size)<bdi>{{ $num($c->nominal_size) }}"</bdi>@else—@endif</td>
        <td class="mono">
          {{ $c->schedule ?? '—' }}
          @if($c->template_code === 'PIPE' && $line->pms_class_snapshot_id !== null)
            @php $check = $scheduleChecks[$c->id] ?? null; @endphp
            @if($check === null)
              <div style="font-size:11px;color:#fcd34d;">{{ $L('scheduleUnknown') }}</div>
            @elseif($check['matches'])
              <div style="font-size:11px;color:#86efac;">{{ $L('scheduleMatches') }}</div>
            @else
              <div style="font-size:11px;color:#fca5a5;">{{ $L('scheduleDiffers') }} · {{ $L('expected') }}: {{ $check['expected'] }}</div>
              @if(isset($pendingScheduleRequests[$c->id]))
                <div style="font-size:11px;color:#fcd34d;">{{ $L('reviewPending') }}</div>
                @if($pendingScheduleRequests[$c->id]['remedy'] === 'INTERNAL_TAPER_PROPOSED')
                  <div style="font-size:11px;color:#fcd34d;">{{ $L('taperProposal') }}</div>
                @endif
              @elseif($canCapture && $c->company_id === $user->company_id && $line->company_id === $user->company_id)
                <form method="POST" action="/projects/{{ $project->code }}/piping/{{ $line->id }}/model/{{ $c->id }}/request-review?{{ http_build_query($q) }}" style="margin-top:6px;">
                  @csrf
                  @if($check['taper_eligible'])
                    <select name="proposed_remedy" aria-label="{{ $L('taperProposal') }}"
                            style="width:100%;min-width:170px;background:var(--bg);color:inherit;border:1px solid var(--line);border-radius:6px;">
                      <option value="UNDETERMINED">{{ $L('remedyUndetermined') }}</option>
                      <option value="INTERNAL_TAPER_PROPOSED">{{ $L('taperProposal') }}</option>
                    </select>
                  @endif
                  <textarea name="reason" required minlength="10" maxlength="4000" rows="2"
                            placeholder="{{ $L('requestReason') }}" aria-label="{{ $L('requestReason') }}"
                            style="width:100%;min-width:170px;background:var(--bg);color:inherit;border:1px solid var(--line);border-radius:6px;"></textarea>
                  <button type="submit" style="font:inherit;font-size:11px;cursor:pointer;">{{ $L('requestReview') }}</button>
                </form>
              @endif
            @endif
          @endif
          @if(($requestHistory[$c->id] ?? collect())->isNotEmpty())
            <details style="margin-top:8px;font-size:11px;">
              <summary>{{ $L('requestHistory') }} ({{ $num($requestHistory[$c->id]->count()) }})</summary>
              @foreach($requestHistory[$c->id] as $entry)
                <div style="border-top:1px solid var(--line);padding:5px 0;white-space:normal;">
                  <bdi>{{ $entry->created_at }}</bdi> ·
                  @if($entry->nps_at_request)<bdi>{{ $num($entry->nps_at_request) }}" (DN{{ $num($entry->dn_at_request) }})</bdi> · @endif
                  {{ $entry->actual_schedule }} / {{ $entry->expected_schedule }}
                  @if($entry->actual_schedule !== $c->schedule || $entry->pms_class_snapshot_id !== $line->pms_class_snapshot_id
                      || $entry->nps_at_request !== $c->nominal_size)
                    <div style="color:#fcd34d;">{{ $L('previousValue') }}</div>
                  @elseif(! isset($scheduleChecks[$c->id]) || $entry->expected_schedule !== $scheduleChecks[$c->id]['expected']
                      || (int) $entry->dn_at_request !== $scheduleChecks[$c->id]['dn'])
                    <div style="color:#fcd34d;">{{ $L('unverifiedPmsClaim') }}</div>
                  @endif
                  @if($entry->proposed_remedy === 'INTERNAL_TAPER_PROPOSED')
                    <div>{{ $L('taperProposal') }}</div>
                  @endif
                  <div>{{ $entry->reason }}</div>
                  @if($entry->decision_id !== null)
                    <div style="color:#fcd34d;">{{ $L('reviewRecorded') }}</div>
                  @else
                    <div style="color:#fcd34d;">{{ $L('reviewPending') }}</div>
                  @endif
                  @foreach($reportHistory[$entry->id] ?? [] as $report)
                    <div>{{ $report->kind }} ·
                      @if($report->origin === 'IN_SYSTEM')
                        {{ $L('internalDraft') }}
                      @else
                        {{ $L('externalMetadata') }} · <bdi>{{ $report->report_number }}</bdi>
                        <details style="margin:4px 0 8px 12px;">
                          <summary>{{ $L('reportDetails') }}</summary>
                          <div>{{ $L('reportNumber') }}: <bdi>{{ $report->report_number }}</bdi></div>
                          @if($report->report_revision !== null)
                            <div>{{ $L('reportRevision') }}: <bdi>{{ $report->report_revision }}</bdi></div>
                          @endif
                          <div>{{ $L('reportIssuer') }}: {{ $report->reported_issuer }}</div>
                          <div>{{ $L('reportSigner') }}: {{ $report->reported_signer }}</div>
                          <div>{{ $L('reportCapacity') }}: {{ $report->reported_signer_capacity }}</div>
                          <div>{{ $L('reportIssuedAt') }}: <bdi>{{ substr((string) $report->reported_issued_at, 0, 10) }}</bdi></div>
                          <div>{{ $L('reportSignedAt') }}: <bdi>{{ substr((string) $report->reported_signed_at, 0, 10) }}</bdi></div>
                          <div>{{ $L('reportRegister') }}: <bdi>{{ $report->external_register_ref }}</bdi></div>
                          <div>{{ $L('reportRecordedAt') }}: <bdi>{{ $report->created_at }}</bdi></div>
                          <div>{{ $L('reportRecorder') }}: <bdi>{{ $report->recorded_by }}</bdi></div>
                        </details>
                      @endif
                    </div>
                  @endforeach
                  @if($canCapture && $c->company_id === $user->company_id && $line->company_id === $user->company_id)
                    @if($entry->nps_at_request !== null && $entry->nps_at_request === $c->nominal_size
                        && isset($scheduleChecks[$c->id])
                        && (int) $entry->dn_at_request === $scheduleChecks[$c->id]['dn']
                        && $entry->expected_schedule === $scheduleChecks[$c->id]['expected']
                        && $entry->actual_schedule === $c->schedule && $entry->pms_class_snapshot_id === $line->pms_class_snapshot_id)
                      <form method="POST" action="/projects/{{ $project->code }}/piping/{{ $line->id }}/model/reports/{{ $entry->id }}/draft?{{ http_build_query($q) }}" style="margin-top:6px;">
                        @csrf
                        <select name="kind" aria-label="TQ/NCR"><option value="TQ">TQ</option><option value="NCR">NCR</option></select>
                        <button type="submit">{{ $L('captureDraft') }}</button>
                      </form>
                    @endif
                    <details style="margin-top:6px;">
                      <summary>{{ $L('captureExternal') }}</summary>
                      <form method="POST" action="/projects/{{ $project->code }}/piping/{{ $line->id }}/model/reports/{{ $entry->id }}/external?{{ http_build_query($q) }}" style="display:grid;gap:5px;max-width:330px;margin-top:5px;">
                        @csrf
                        <select name="kind" aria-label="TQ/NCR"><option value="TQ">TQ</option><option value="NCR">NCR</option></select>
                        <input name="report_number" required maxlength="160" placeholder="{{ $L('reportNumber') }}" aria-label="{{ $L('reportNumber') }}">
                        <input name="report_revision" maxlength="64" placeholder="{{ $L('reportRevision') }}" aria-label="{{ $L('reportRevision') }}">
                        <input name="reported_issuer" required maxlength="200" placeholder="{{ $L('reportIssuer') }}" aria-label="{{ $L('reportIssuer') }}">
                        <input name="reported_signer" required maxlength="200" placeholder="{{ $L('reportSigner') }}" aria-label="{{ $L('reportSigner') }}">
                        <input name="reported_signer_capacity" required maxlength="200" placeholder="{{ $L('reportCapacity') }}" aria-label="{{ $L('reportCapacity') }}">
                        <label>{{ $L('reportIssuedAt') }} <input type="date" name="reported_issued_at" required></label>
                        <label>{{ $L('reportSignedAt') }} <input type="date" name="reported_signed_at" required></label>
                        <input name="external_register_ref" required maxlength="512" placeholder="{{ $L('reportRegister') }}" aria-label="{{ $L('reportRegister') }}">
                        <button type="submit">{{ $L('captureExternal') }}</button>
                      </form>
                    </details>
                  @endif
                </div>
              @endforeach
            </details>
          @endif
        </td>
        <td style="font-size:12px;">
          @foreach($c->ports as $p)
            {{-- Open vs connected must be legible at a glance, and colour
                 alone does not do it: on a dark background the two pills were
                 near-identical in a screenshot, and a colour-blind reader gets
                 nothing from green-vs-grey either. So the OPEN state also
                 carries a dashed border and a bullet glyph. --}}
            <span style="display:inline-block;margin:1px 3px 1px 0;padding:1px 7px;border-radius:999px;font-size:11px;
                         border:1px {{ $p->is_connected ? 'solid #15803d' : 'dashed #f59e0b' }};
                         background:{{ $p->is_connected ? 'rgba(21,128,61,.28)' : 'rgba(245,158,11,.12)' }};
                         color:{{ $p->is_connected ? '#86efac' : '#fcd34d' }};"
                  title="{{ $p->is_connected ? ($fa ? 'متصل' : 'connected') : ($fa ? 'باز' : 'open') }}">
              {{-- bdi, because this is a latin-and-digits token inside an RTL
                   paragraph: without it the trailing inch mark is reordered to
                   the front and the pill reads  "P2 RUN 6  in Farsi. --}}
              {{ $p->is_connected ? '●' : '○' }}
              <bdi>P{{ $num($p->seq) }} {{ $p->role }}{{ $p->nominal_size ? ' '.$num($p->nominal_size).'"' : '' }}</bdi>
            </span>
          @endforeach
        </td>
        <td class="mono">{{ $c->length_mm ? $num(rtrim(rtrim(number_format((float) $c->length_mm, 1, '.', ''), '0'), '.')) : '—' }}</td>
        <td style="min-width:250px;max-width:420px;white-space:normal;">
          @include('piping.partials.component-geometry', [
            'component' => $c,
            'geometry' => $componentGeometry[$c->id] ?? ['status' => 'UNRESOLVED', 'reason' => 'GEOMETRY_NOT_AVAILABLE', 'ports' => []],
          ])
        </td>
        <td>
          @if($editable)
          <form method="POST" action="/projects/{{ $project->code }}/piping/{{ $line->id }}/model/{{ $c->id }}/delete?{{ http_build_query($q) }}"
                onsubmit="return confirm('{{ $L('remove') }}?')">
            @csrf
            <button type="submit"
                    style="padding:4px 10px;border-radius:6px;border:1px solid var(--line);background:transparent;color:#fca5a5;font:inherit;font-size:12px;cursor:pointer;">
              {{ $L('remove') }}
            </button>
          </form>
          @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="8" style="color:var(--muted);">{{ $L('none') }}</td></tr>
    @endforelse
    </tbody>
  </table>

  {{-- ------------------------------------------------------- joints ----- --}}
  <h2 style="margin-top:28px;font-size:16px;">{{ $L('joints') }}
    <span style="font-weight:400;font-size:11.5px;color:var(--muted);margin-inline-start:10px;">
      {{-- Each metric is isolated WHOLE, number and label together. Isolating
           only the number leaves the "·" separator as a neutral character
           between a digit run and a word, and the bidi algorithm attaches it
           to the digits: "6 welds · 2 fitted · 28" rendered in Farsi as
           "۶ جوش ۲۰ مونتاژشده ۲۸۰". The numbers were right; the punctuation
           moved. --}}
      <bdi>{{ $num($metrics['welds']) }} {{ $L('jwelds') }}</bdi>
      <span style="color:var(--line);">|</span>
      <bdi>{{ $num($metrics['fitted']) }} {{ $L('jfitted') }}</bdi>
      <span style="color:var(--line);">|</span>
      <bdi>{{ $num(rtrim(rtrim(number_format($metrics['dia_inch'], 2, '.', ''), '0'), '.')) }} {{ $L('jdia') }}</bdi>
      @if(($metrics['branch_pad_welds'] ?? 0) > 0)
        <span style="color:var(--line);">|</span>
        <bdi>{{ $num($metrics['branch_pad_welds']) }} {{ $L('padWeldMetrics') }}</bdi>
      @endif
      @if(($metrics['branch_pad_welds_on_line_registered_elsewhere'] ?? 0) > 0)
        <span style="color:var(--line);">|</span>
        <bdi>{{ $num($metrics['branch_pad_welds_on_line_registered_elsewhere']) }} {{ $L('padPhysicalElsewhere') }}</bdi>
      @endif
    </span>
  </h2>
  <p style="color:var(--muted);font-size:11.5px;margin:4px 0 0;">{{ $L('jdianote') }}</p>
  <div style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-top:8px;">
    <small style="color:var(--muted);max-width:760px;line-height:1.6;">{{ $L('snapshotHelp') }}</small>
    @if($canEditPose && $joints->isNotEmpty())
      <form method="POST" action="/projects/{{ $project->code }}/piping/{{ $line->id }}/model/geometry-snapshots?{{ http_build_query($q) }}">
        @csrf
        <button type="submit" style="padding:6px 10px;border-radius:6px;border:1px solid var(--line);background:var(--card);color:inherit;font:inherit;font-size:12px;cursor:pointer;">
          {{ $L('snapshotAction') }}
        </button>
      </form>
    @endif
  </div>

  <table data-tt style="margin-top:10px;">
    <thead>
      <tr>
        <th data-sort="number">{{ $L('jnum') }}</th>
        <th data-sort="text" data-facet>{{ $L('jtype') }}</th>
        <th>{{ $L('jbetween') }}</th>
        <th data-sort="text" data-facet>{{ $L('jstate') }}</th>
        <th data-sort="number">{{ $L('jdia') }}</th>
        <th>{{ $L('jointGeometry') }}</th>
      </tr>
    </thead>
    <tbody>
    @forelse($joints as $j)
      @php
        $dia = $diaInch($j);
        $cA = $j->portA?->component;
        $cB = $j->portB?->component;
        $physicalLine = $cA?->line ?? $cB?->line;
        $wallHeader = $j->branchHeaderComponent;
        $wallTarget = $j->branchWallComponent;
        $station = $j->branch_station_mm === null
            ? null
            : rtrim(rtrim(number_format((float) $j->branch_station_mm, 3, '.', ''), '0'), '.');
      @endphp
      <tr>
        <td class="mono">
          @if($j->displayNumber())
            <bdi>{{ $num($j->displayNumber()) }}</bdi>
          @else
            {{-- A flanged joint is NOT an unnumbered weld awaiting a number:
                 it will never have one. Showing a blank cell would put it on
                 somebody's chase-list forever. --}}
            <span style="color:var(--muted);font-size:11px;">{{ $L('junnum') }}</span>
          @endif
        </td>
        <td style="font-size:12px;"><bdi>{{ $j->joint_type }}</bdi>
          @if($j->compatibility_state !== 'VALID')
            <span style="display:inline-block;margin-inline-start:6px;padding:1px 7px;border-radius:999px;font-size:10.5px;
                         border:1px dashed #f59e0b;background:rgba(245,158,11,.12);color:#fcd34d;"
                  title="{{ implode(' · ', collect($j->compatibility_notes['reasons'] ?? [])->map(fn ($r) => __('epcc.joint.reason.'.$r['code'], $r['params']))->all()) }}">
              ⚠ {{ $j->compatibility_state }}
            </span>
          @endif
        </td>
        <td style="font-size:12px;">
          <bdi>{{ $cA ? $num($cA->sequence).' '.$cA->template_code : '?' }}</bdi>
          <span style="color:var(--muted);">↔</span>
          @if($cB)
            <bdi>{{ $num($cB->sequence).' '.$cB->template_code }}</bdi>
          @elseif($wallHeader)
            <bdi>{{ $num($wallHeader->sequence).' '.$wallHeader->template_code }}</bdi>
            <span style="color:var(--muted);">({{ $L('wall') }}@if($station !== null) · <bdi>{{ $L('station') }} {{ $num($station) }} mm</bdi>@endif @if($j->branch_longitudinal_angle_deg !== null) · <bdi>β {{ $num($j->branch_longitudinal_angle_deg) }}°</bdi>@endif)</span>
          @elseif($wallTarget)
            <bdi>{{ $num($wallTarget->sequence).' '.$wallTarget->template_code }}</bdi>
            <span style="color:var(--muted);">({{ $L('padFilletTo') }} {{ $j->branch_pad_side === 'HEADER' ? $L('padSideHeader') : $L('padSideBranch') }})</span>
          @else
            <bdi>?</bdi>
          @endif
          @if($physicalLine && $physicalLine->id !== $line->id)
            <small style="display:block;color:var(--muted);font-size:10px;">{{ $L('jointPhysicalLine') }}: <bdi>{{ $physicalLine->line_number }}</bdi></small>
          @endif
          @if($j->isBranchPadFillet() && $j->line && $j->line->id !== $physicalLine?->id)
            <small style="display:block;color:var(--muted);font-size:10px;">{{ $L('padOwner') }}: <bdi>{{ $j->line->line_number }}</bdi></small>
          @endif
        </td>
        <td style="font-size:12px;">
          <span style="display:inline-block;padding:1px 8px;border-radius:999px;font-size:11px;
                       border:1px {{ $j->isFitted() ? 'solid #15803d' : 'dashed var(--line)' }};
                       background:{{ $j->isFitted() ? 'rgba(21,128,61,.28)' : 'transparent' }};
                       color:{{ $j->isFitted() ? '#86efac' : 'var(--muted)' }};">
            {{ $j->isFitted() ? '●' : '○' }} <bdi>{{ $j->state }}</bdi>
          </span>
        </td>
        <td class="mono">
          @if($dia !== null)
            <bdi>{{ $num(rtrim(rtrim(number_format($dia, 2, '.', ''), '0'), '.')) }}</bdi>
          @else
            <span style="color:var(--muted);">—</span>
          @endif
        </td>
        <td style="min-width:230px;white-space:normal;font-size:11px;">
          @php $diagnostic = $jointGeometry[$j->id] ?? ['status' => 'UNRESOLVED', 'reason' => 'DIAGNOSTIC_NOT_AVAILABLE']; @endphp
          @if(($diagnostic['snapshot_state'] ?? null) === 'NOT_CAPTURED')
            <small style="display:block;color:var(--muted);">{{ $L('snapshotMissing') }}</small>
          @elseif(($diagnostic['snapshot_state'] ?? null) === 'CURRENT')
            <small style="display:block;color:var(--muted);">{{ $L('snapshotCurrent') }} <bdi>{{ $num($diagnostic['snapshot_revision']) }}</bdi></small>
          @endif
          @if(($diagnostic['snapshot_state'] ?? null) === 'STALE')
            <span style="color:#fca5a5;">{{ $L('snapshotStale') }}</span>
            <div class="mono" style="color:var(--muted);">{{ $diagnostic['reason'] }}</div>
          @elseif($diagnostic['status'] === 'UNASSESSED')
            <strong style="color:#fcd34d;">{{ $L('unassessed') }}</strong>
            <div>{{ $L('gap') }}: <bdi>{{ $formatMm($diagnostic['gap_mm']) }} mm</bdi></div>
            <div>{{ $L('axisDeviation') }}: <bdi>{{ $formatMm($diagnostic['outward_axis_deviation_deg']) }}°</bdi></div>
            <small style="color:var(--muted);">{{ $L('unassessedHelp') }}</small>
          @elseif($diagnostic['status'] === 'STALE')
            <span style="color:#fca5a5;">{{ $L('checkStale') }}</span>
            <div class="mono" style="color:var(--muted);">{{ $diagnostic['reason'] }}</div>
          @else
            <span style="color:#fcd34d;">{{ $L('checkUnresolved') }}</span>
            <div class="mono" style="color:var(--muted);">{{ $diagnostic['reason'] }}</div>
          @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="6" style="color:var(--muted);">{{ $L('jnone') }}</td></tr>
    @endforelse
    </tbody>
  </table>

  {{-- ------------------------------------------------ add standalone ---- --}}
  @if($editable)
  <div style="margin-top:22px;border:1px solid var(--line);border-radius:10px;background:var(--card);padding:14px;">
    <h2 style="margin:0 0 4px;font-size:15px;">{{ $L('add') }}</h2>
    <p style="color:var(--muted);font-size:12px;margin:0 0 12px;line-height:1.7;">{{ $L('portsnote') }}</p>

    <form method="POST" action="/projects/{{ $project->code }}/piping/{{ $line->id }}/model?{{ http_build_query($q) }}"
          style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;align-items:end;">
      @csrf

      <label style="font-size:12px;color:var(--muted);">
        {{ $L('type') }}
        <select name="template_code" required
                style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          @foreach($templates as $tpl)
            <option value="{{ $tpl['code'] }}" @selected(old('template_code') === $tpl['code'])>
              {{ $tpl['name'][$lang] ?? $tpl['name']['en'] }} — {{ $num($tpl['ports']) }} {{ $L('ports') }}
            </option>
          @endforeach
        </select>
      </label>

      <label style="font-size:12px;color:var(--muted);">
        {{ $L('elbowStandard') }}
        <select name="fitting_standard"
                style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          <option value="">—</option>
          <option value="B16.9" @selected(old('fitting_standard') === 'B16.9')>ASME B16.9-2018 · BW LR</option>
          <option value="B16.11" @selected(old('fitting_standard') === 'B16.11')>ASME B16.11-2021 · forged</option>
        </select>
        <small style="display:block;margin-top:3px;line-height:1.5;">{{ $L('elbowStandardHelp') }}</small>
      </label>

      <label style="font-size:12px;color:var(--muted);">
        {{ $L('elbowQualifier') }}
        <select name="fitting_qualifier"
                style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          <option value="">—</option>
          <optgroup label="B16.9 · BW long radius">
            <option value="90LR" @selected(old('fitting_qualifier') === '90LR')>90° LR</option>
            <option value="45LR" @selected(old('fitting_qualifier') === '45LR')>45° LR</option>
          </optgroup>
          <optgroup label="B16.11 · angle only">
            <option value="90" @selected(old('fitting_qualifier') === '90')>90°</option>
            <option value="45" @selected(old('fitting_qualifier') === '45')>45°</option>
          </optgroup>
        </select>
        <small style="display:block;margin-top:3px;line-height:1.5;">{{ $L('elbowQualifierHelp') }}</small>
      </label>

      <label style="font-size:12px;color:var(--muted);">
        {{ $L('elbowClass') }}
        <select name="fitting_class_designation"
                style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          <option value="">—</option>
          @foreach([2000, 3000, 6000, 9000] as $classDesignation)
            <option value="{{ $classDesignation }}" @selected((string) old('fitting_class_designation') === (string) $classDesignation)>{{ $classDesignation }}</option>
          @endforeach
        </select>
        <small style="display:block;margin-top:3px;line-height:1.5;">{{ $L('elbowClassHelp') }}</small>
      </label>

      <label style="font-size:12px;color:var(--muted);">
        {{ $L('elbowEndType') }}
        <select name="fitting_end_type"
                style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          <option value="">—</option>
          <option value="BW" @selected(old('fitting_end_type') === 'BW')>BW</option>
          <option value="SW" @selected(old('fitting_end_type') === 'SW')>SW</option>
          <option value="SC" @selected(old('fitting_end_type') === 'SC')>SC · threaded</option>
        </select>
        <small style="display:block;margin-top:3px;line-height:1.5;">{{ $L('elbowEndTypeHelp') }}</small>
      </label>

      <label style="font-size:12px;color:var(--muted);">
        {{ $L('size') }}
        <select name="nominal_size"
                style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          @foreach($sizes as $s)
            <option value="{{ $s }}" @selected(old('nominal_size', $line->nominal_size) === $s)>{{ $inch($s) }}</option>
          @endforeach
        </select>
      </label>

      <label style="font-size:12px;color:var(--muted);">
        {{ $L('branch') }}
        <select name="branch_size"
                style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          <option value="">—</option>
          @foreach($sizes as $s)
            <option value="{{ $s }}" @selected(old('branch_size') === $s)>{{ $inch($s) }}</option>
          @endforeach
        </select>
      </label>

      <label style="font-size:12px;color:var(--muted);">
        {{ $L('small') }}
        <select name="small_size"
                style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
          <option value="">—</option>
          @foreach($sizes as $s)
            <option value="{{ $s }}" @selected(old('small_size') === $s)>{{ $inch($s) }}</option>
          @endforeach
        </select>
      </label>

      <label style="font-size:12px;color:var(--muted);">
        {{ $L('lenmm') }}
        <input type="number" step="any" min="0" name="length_mm" value="{{ old('length_mm') }}"
               style="width:100%;margin-top:4px;padding:6px;border-radius:6px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;">
        <small style="display:block;margin-top:3px;line-height:1.5;">{{ $L('lengthGeometryHelp') }}</small>
      </label>

      <button type="submit"
              style="padding:7px 14px;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:inherit;font:inherit;cursor:pointer;">
        {{ $L('addbtn') }}
      </button>
    </form>
  </div>
  @endif

  <div class="foot">
    {{ $fa
        ? 'تعداد و نقش پورت‌ها از تمپلیت قطعه می‌آید، نه از ورودی کاربر؛ سایز هنگام اتصال از پورت والد به ارث می‌رسد. به همین دلیل «لولهٔ ۶ اینچ روی انشعاب ۲ اینچ» اصلاً قابل بیان نیست.'
        : 'Port count and roles come from the component template, not from user input; size is inherited from the parent port on attachment. That is why "6\" pipe onto a 2\" branch" is not expressible at all.' }}
  </div>
</div>
</div>
</body>
</html>
