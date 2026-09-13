{{--
  The sidebar toggle.

  Deliberately OUTSIDE the sidebar: a control that collapses the sidebar must
  remain reachable once it has collapsed, and on mobile the sidebar is off
  canvas entirely - a button inside it would slide away with it and there would
  be no way to get it back.

  Rendered at the top of every shell screen, in the content column.
--}}
<div class="topbar">
  <button type="button" class="sb-burger" id="sb-burger"
          aria-controls="sb" aria-expanded="true"
          aria-label="{{ $lang === 'fa' ? 'باز و بستن منو' : 'Toggle navigation' }}"
          title="{{ $lang === 'fa' ? 'باز و بستن منو' : 'Toggle navigation' }}">&#9776;</button>
  @isset($topbarTitle)
    <span class="tb-t">{{ $topbarTitle }}</span>
  @endisset
</div>
