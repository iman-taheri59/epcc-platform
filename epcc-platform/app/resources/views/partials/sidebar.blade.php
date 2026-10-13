{{--
  Entitlement-driven sidebar.

  Every item comes from App\Support\Navigation, which reads the company's plan
  and the user's capabilities - the same two gates the authorization pipeline
  uses. The menu therefore cannot offer something the pipeline would refuse.

  Locked and planned items are rendered as <span>, never <a>: no href means
  they cannot be clicked, middle-clicked or crawled into a denial.
--}}
@php
    $fa = $lang === 'fa';
@endphp
{{-- Tap-to-close backdrop for the mobile drawer. Inert on desktop. --}}
<div class="scrim" id="scrim" aria-hidden="true"></div>

<aside class="sb" id="sb" aria-label="{{ $fa ? 'ناوبری اصلی' : 'Main navigation' }}">
  <div class="sb-brand">
    <span class="sb-logo" aria-hidden="true">EP</span>
    <span class="sb-brand-t">
      <b>{{ $fa ? 'سامانهٔ EPCC' : 'EPCC Platform' }}</b>
      <small>{{ $acting->code ?? '' }}</small>
    </span>
  </div>

  <nav class="sb-nav">
    @foreach($nav as $section)
      @if(count($section['items']) > 0)
        <div class="sb-sec">{{ $section['label'] }}</div>
        @foreach($section['items'] as $item)
          @if($item['state'] === 'available')
            <a class="sb-i{{ $item['active'] ? ' on' : '' }}"
               href="{{ $item['url'] }}"
               @if($item['active']) aria-current="page" @endif>
              <i class="sb-ic">{!! $item['icon'] !!}</i>
              <span>{{ $item['label'] }}</span>
            </a>
          @else
            {{-- Inert on purpose. title= carries the reason, so the user is
                 never left guessing whether it is a bug or their plan. --}}
            <span class="sb-i off {{ $item['state'] }}" title="{{ $item['hint'] }}" aria-disabled="true">
              <i class="sb-ic">{!! $item['icon'] !!}</i>
              <span>{{ $item['label'] }}</span>
              <em class="sb-b {{ $item['state'] }}">{{ $item['badge'] }}</em>
            </span>
          @endif
        @endforeach
      @endif
    @endforeach
  </nav>

  <div class="sb-foot">
    <div class="sb-user">
      <span class="sb-av" aria-hidden="true">{{ mb_substr($user->name, 0, 1) }}</span>
      <span class="sb-user-t">
        <b>{{ $user->name }}</b>
        <small>{{ $acting->name($lang) ?? '' }}</small>
      </span>
    </div>

    @if(($plan ?? null) !== null)
      <div class="sb-plan">
        <span>{{ $fa ? 'پلن' : 'Plan' }}</span>
        <span class="pill pack">{{ $plan->name($lang) }}</span>
      </div>
    @endif

    <div class="sb-acts">
      <a href="{{ $langSwitchUrl ?? ('?lang='.($fa ? 'en' : 'fa')) }}">{{ $fa ? 'English' : 'فارسی' }}</a>
      <form method="post" action="/logout">
        @csrf
        <button type="submit">{{ $fa ? 'خروج' : 'Sign out' }}</button>
      </form>
    </div>
  </div>
</aside>

{{--
  Collapse / expand.

  Ported from prototype/index.html, whose behaviour is pinned by 19 jsdom
  assertions (prototype/test/sidebar.test.js). Same class names so the two
  implementations cannot drift.

  Desktop  -> body.rail   (icon rail, labels hidden)
  Mobile   -> body.drawer (off-canvas, scrim + Escape + nav-click close it)

  The choice is remembered in localStorage so it survives navigation - this app
  is server-rendered, so every click is a fresh document and an unremembered
  sidebar would spring back open on every page.
--}}
<script>
(function () {
  // WAIT FOR THE DOM.
  //
  // This partial renders the sidebar, which must come first in the document
  // for the flex layout - but the toggle button lives in the content column
  // (partials/topbar), i.e. LATER in the document. Running immediately meant
  // getElementById('sb-burger') returned null and the whole script bailed at
  // the guard below: the button rendered, looked right, and did nothing.
  //
  // Do not "fix" this by moving the button into the sidebar - it has to stay
  // outside, or it slides off-canvas with the drawer on mobile and strands
  // the user. Waiting for DOMContentLoaded is the fix, and it keeps this
  // partial independent of include order.
  function init() {
  var body = document.body,
      burger = document.getElementById('sb-burger'),
      scrim = document.getElementById('scrim');

  if (!burger) return;

  // Feature-detected: on older Safari/Android WebViews matchMedia or its
  // addEventListener may be missing, and an unguarded call kills the whole
  // script - which previously blanked the prototype.
  var mq = window.matchMedia ? window.matchMedia('(max-width:860px)') : null;
  var MOBILE = function () { return mq ? mq.matches : (window.innerWidth || 1024) <= 860; };

  var KEY = 'epcc.sb.rail';

  // Restore the desktop preference before the first paint below.
  // <html> already carries the class from the pre-paint script in the shell;
  // mirror it onto <body> so both selectors agree from here on.
  if (document.documentElement.classList.contains('rail')) body.classList.add('rail');

  function sync() {
    var open = MOBILE() ? body.classList.contains('drawer') : !body.classList.contains('rail');
    burger.setAttribute('aria-expanded', String(open));
    burger.textContent = (MOBILE() && open) ? '\u2715' : '\u2630';
  }

  function toggle() {
    if (MOBILE()) {
      body.classList.toggle('drawer');
    } else {
      var railed = body.classList.toggle('rail');
      document.documentElement.classList.toggle('rail', railed);
      try { localStorage.setItem(KEY, railed ? '1' : '0'); } catch (e) {}
    }
    sync();
  }

  function closeDrawer() {
    if (body.classList.contains('drawer')) { body.classList.remove('drawer'); sync(); }
  }

  burger.addEventListener('click', toggle);
  if (scrim) scrim.addEventListener('click', closeDrawer);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeDrawer(); });

  // Following a link closes the drawer; otherwise it stays over the new page.
  var nav = document.querySelector('.sb-nav');
  if (nav) nav.addEventListener('click', function (e) { if (e.target.closest('a')) closeDrawer(); });

  // Crossing the breakpoint must not leave a stale state behind.
  var onBreak = function () { body.classList.remove('drawer'); sync(); };
  if (mq && mq.addEventListener) mq.addEventListener('change', onBreak);
  else if (mq && mq.addListener) mq.addListener(onBreak); // legacy Safari
  else window.addEventListener('resize', onBreak);

  sync();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
</script>
