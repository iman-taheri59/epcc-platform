@php
    $fa = $lang === 'fa';
    $t = [
        'title'    => ['fa' => 'ورود به سامانه', 'en' => 'Sign in'],
        'sub'      => ['fa' => 'سامانهٔ مدیریت پروژه‌های EPCC', 'en' => 'EPCC Project Management Platform'],
        'email'    => ['fa' => 'پست الکترونیک', 'en' => 'Email'],
        'password' => ['fa' => 'گذرواژه', 'en' => 'Password'],
        'remember' => ['fa' => 'مرا به خاطر بسپار', 'en' => 'Remember me'],
        'submit'   => ['fa' => 'ورود', 'en' => 'Sign in'],
        'demo'     => ['fa' => 'حساب‌های نمایشی (گذرواژهٔ همه: password)', 'en' => 'Demo accounts (password for all: password)'],
        'hint'     => ['fa' => 'برای پر شدن خودکار فرم، روی یک حساب کلیک کنید.', 'en' => 'Click an account to fill the form.'],
    ];
    $L = fn (string $k) => $t[$k][$lang];
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $L('title') }}</title>
<style>
  :root { --bg:#0f172a; --card:#1e293b; --line:#334155; --muted:#94a3b8; --accent:#8b5cf6; --bad:#ef4444; }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:#e2e8f0; min-height:100vh;
    font-family:{{ $fa ? "Vazirmatn, Tahoma, sans-serif" : "ui-sans-serif, system-ui, sans-serif" }};
    display:flex; align-items:center; justify-content:center; padding:24px; line-height:1.7; }
  .wrap { width:100%; max-width:940px; display:grid; grid-template-columns:1fr 1fr; gap:22px; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:26px; }
  h1 { font-size:21px; margin:0 0 4px; }
  .sub { color:var(--muted); font-size:13.5px; margin-bottom:22px; }
  label { display:block; font-size:13px; color:var(--muted); margin:14px 0 6px; }
  input[type=email], input[type=password] { width:100%; background:#0b1220; color:#e2e8f0;
    border:1px solid var(--line); border-radius:9px; padding:11px 13px; font-size:14px;
    font-family:inherit; direction:ltr; text-align:{{ $fa ? 'right' : 'left' }}; }
  input:focus { outline:none; border-color:var(--accent); }
  .row { display:flex; align-items:center; gap:8px; margin-top:14px; font-size:13px; color:var(--muted); }
  button { width:100%; margin-top:20px; background:var(--accent); color:#fff; border:0;
    border-radius:9px; padding:12px; font-size:15px; font-weight:600; font-family:inherit; cursor:pointer; }
  button:hover { background:#7c3aed; }
  .err { background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.4); color:#fca5a5;
    border-radius:9px; padding:11px 13px; font-size:13px; margin-bottom:16px; }
  .lang { position:fixed; top:18px; {{ $fa ? 'left' : 'right' }}:18px; }
  .lang a { color:var(--accent); font-size:13px; text-decoration:none; font-weight:600; }
  h2 { font-size:14px; margin:0 0 4px; }
  .hint { font-size:12px; color:var(--muted); margin-bottom:14px; }
  .u { display:flex; justify-content:space-between; gap:10px; align-items:center; width:100%;
    background:#0b1220; border:1px solid var(--line); border-radius:9px; padding:9px 12px;
    margin-bottom:7px; cursor:pointer; text-align:{{ $fa ? 'right' : 'left' }}; color:inherit;
    font-family:inherit; font-size:13px; }
  .u:hover { border-color:var(--accent); }
  .u .who { font-weight:600; }
  .u .mail { font-size:11.5px; color:var(--muted); direction:ltr; }
  .u .co { font-size:11px; color:#c4b5fd; font-family:ui-monospace,monospace; white-space:nowrap; }
  @media (max-width:760px){ .wrap{grid-template-columns:1fr;} }
</style>
</head>
<body>
<div class="lang"><a href="?lang={{ $fa ? 'en' : 'fa' }}">{{ $fa ? 'English' : 'فارسی' }}</a></div>

<div class="wrap">
  <div class="card">
    <h1>{{ $L('title') }}</h1>
    <div class="sub">{{ $L('sub') }}</div>

    @if ($errors->any())
      <div class="err">{{ $errors->first() }}</div>
    @endif

    <form method="post" action="/login">
      @csrf
      <label for="email">{{ $L('email') }}</label>
      <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus>

      <label for="password">{{ $L('password') }}</label>
      <input id="password" name="password" type="password" required>

      <div class="row">
        <input id="remember" name="remember" type="checkbox" value="1">
        <label for="remember" style="margin:0;">{{ $L('remember') }}</label>
      </div>

      <button type="submit">{{ $L('submit') }}</button>
    </form>
  </div>

  <div class="card">
    <h2>{{ $L('demo') }}</h2>
    <div class="hint">{{ $L('hint') }}</div>
    @foreach($demoUsers as $u)
      <button type="button" class="u" onclick="fill('{{ $u->email }}')">
        <span>
          <span class="who">{{ $u->name }}</span><br>
          <span class="mail">{{ $u->email }}</span>
        </span>
        <span class="co">{{ $u->company->code }}</span>
      </button>
    @endforeach
  </div>
</div>

<script>
  function fill(email) {
    document.getElementById('email').value = email;
    document.getElementById('password').value = 'password';
  }
</script>
</body>
</html>
