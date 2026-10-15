<?php
// login.php
// Sign-in page. Shows errors/messages (wrong password, deactivated account,
// "Forgot password?" feedback) and the form na processed by login_process.php.
session_start();

// Already signed in: deretso to the right page for the role (Admin -> dashboard, Staff -> orders)
// If naka-log-in na, redirect to the correct page for the role.
if (isset($_SESSION['user_id'])) {
    header("Location: " . (($_SESSION['role'] ?? '') === 'Admin' ? 'dashboard.php' : 'view_order.php'));
    exit();
}

// Map the short status code galing login_process.php to a message + tone.
// The short status code galing login_process.php maps to a message + tone here.
$error_map = [
    'empty'        => ['Please enter your username and password.', 'error'],
    'invalid'      => ['That username and password did not match. Please try again.', 'error'],
    'unauthorized' => ['Please sign in to continue.', 'info'],
    'deactivated'  => ['Your session ended. Your account may have been deactivated. Please ask an administrator.', 'error'],
    'inactive'     => ['This account is inactive. Please ask an administrator.', 'error'],
    'locked'       => ['Too many failed attempts. Please try again in ' . max(1, min(60, (int)($_GET['mins'] ?? 15))) . ' minute(s).', 'error'],
];
$error_code = $_GET['error'] ?? '';
$flash = $error_map[$error_code] ?? null;
// Wrong password: show how many tries remain before the temporary pause (see login_process.php).
if ($error_code === 'invalid' && isset($_GET['left']) && ctype_digit((string)$_GET['left'])) {
    $left = max(1, min(10, (int)$_GET['left']));
    $flash[0] .= ' ' . $left . ' ' . ($left === 1 ? 'attempt' : 'attempts') . ' left.';
}

// Feedback galing reset_request.php (the "Forgot password?" dialog).
$reset_map = [
    'sent'    => ['Request sent. An administrator will set a new password for you — please follow up with them.', 'info'],
    'pending' => ['Your request is already waiting for an administrator to action.', 'info'],
    'nouser'  => ['We could not find an active account with that username. Check the spelling, or ask your administrator.', 'error'],
    'error'   => ['Sorry, we could not send your request. Please try again.', 'error'],
];
$reset_code = $_GET['reset'] ?? '';
$reset_flash = $reset_map[$reset_code] ?? null;
// A failed attempt reopens the dialog, kaya its message appears inside it.
$reset_in_modal = in_array($reset_code, ['nouser', 'error'], true);
// One banner lang at a time; a sign-in error (e.g. ?error=invalid) wins.
$banner = $flash ?: ($reset_in_modal ? null : $reset_flash);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sparkling Spin — Sign in</title>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">

    <style>
        /* Same faces as the rest of the app: Space Grotesk for numerals and
           display, Inter for text. Files live in assets/fonts/. */
        @font-face {
            font-family: "Inter";
            font-style: normal;
            font-display: swap;
            font-weight: 100 900;
            src: url("assets/fonts/inter-latin-wght-normal.woff2") format("woff2");
        }
        @font-face {
            font-family: "Space Grotesk";
            font-style: normal;
            font-display: swap;
            font-weight: 300 700;
            src: url("assets/fonts/space-grotesk-latin.woff2") format("woff2");
        }
        :root {
            /* the dashboard tokens, mirrored para sign-in shares one language */
            --wd-line: #e3e8f2;
            --wd-line-soft: #edf1f8;
            --wd-ink: #0b1220;
            --wd-ink-2: #33415a;
            --wd-mute: #5b6b80;
            --wd-brand: #2563eb;
            --wd-brand-2: #1d4ed8;
            --wd-jade: #0b7350;
            --wd-coral: #c03434;
            --wd-display: "Space Grotesk", "Inter", system-ui, -apple-system, sans-serif;
            --wd-sh-card:
                0 1px 1px rgba(15, 23, 42, 0.04),
                0 2px 6px -2px rgba(15, 23, 42, 0.06),
                0 16px 32px -26px rgba(15, 23, 42, 0.55);
            --wd-sh-lift:
                0 1px 1px rgba(15, 23, 42, 0.05),
                0 8px 18px -6px rgba(15, 23, 42, 0.1),
                0 26px 44px -30px rgba(15, 23, 42, 0.6);
            /* kept as aliases so every rule below still resolves */
            --wt-navy: var(--wd-ink);
            --wt-blue: var(--wd-brand);
            --wt-indigo: #6366f1;
            --wt-bg: #f5f7fb;
            --wt-border: var(--wd-line);
            --wt-muted: var(--wd-mute);
            --wt-grad: linear-gradient(180deg, #3b82f6, var(--wd-brand-2));
            --ink-strong: var(--wd-ink);
            --ink-soft: var(--wd-ink-2);
            --ink-faint: var(--wd-mute);
            --blue-deep: var(--wd-brand-2);
            --blue-ink: #0f2e6e;
            --radius: 20px;
            --font-display: var(--wd-display);
            --font-ui: "Inter", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            --font-mono: var(--wd-display);
        }

        html, body { height: 100%; }
        body {
            margin: 0;
            font-family: var(--font-ui);
            background: var(--wt-bg);
            color: var(--wt-navy);
            -webkit-font-smoothing: antialiased;
        }

        /* Two flush halves, full height — ang classic split */
        .login-wrap { display: flex; min-height: 100vh; min-height: 100dvh; }

        /* ===================== BUBBLES (interactive) ===================== */
        .bubbles { position: absolute; inset: 0; pointer-events: none; z-index: 0; }
        .bubble {
            position: absolute;
            left: 0; top: 0;
            border-radius: 50%;
            background: radial-gradient(circle at 30% 26%, rgba(199,210,254,.38), rgba(129,140,248,.14) 55%, rgba(99,102,241,.04) 100%);
            border: 1px solid rgba(199,210,254,.22);
            box-shadow: inset 0 0 16px rgba(165,180,252,.22), inset -5px -7px 12px rgba(125,211,252,.10);
            pointer-events: auto;
            cursor: grab;
            touch-action: none;
            user-select: none;
            will-change: transform;
            transition: border-color .2s;
        }
        .bubble.soft { background: rgba(165,180,252,.10); border-color: rgba(165,180,252,.14); }
        /* maliit na window-light shine */
        .bubble::after {
            content: ""; position: absolute; top: 13%; left: 19%; width: 28%; height: 15%;
            border-radius: 50%; background: rgba(255,255,255,.55); filter: blur(1px);
            transform: rotate(-32deg); pointer-events: none;
        }
        .bubble.soft::after { opacity: .45; }
        .bubble:hover { border-color: rgba(224,231,255,.45); }
        .bubble.grabbed { cursor: grabbing; border-color: rgba(224,231,255,.55); }

        /* droplets kapag a bubble pops */
        .drop {
            position: absolute; left: 0; top: 0;
            width: 7px; height: 7px; border-radius: 50%;
            background: rgba(224,231,255,.85);
            pointer-events: none;
            animation: dropOut .6s ease-out forwards;
        }
        @keyframes dropOut {
            from { transform: translate(var(--x), var(--y)) scale(1); opacity: 1; }
            to   { transform: translate(calc(var(--x) + var(--dx)), calc(var(--y) + var(--dy))) scale(.2); opacity: 0; }
        }

        /* ===================== LEFT PANEL — identity (kaliwa) ===================== */
        .login-left {
            position: relative;
            overflow: hidden;
            flex: 0 0 44%;
            padding: 40px 48px 48px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            color: #fff;
            /* ang dashboard's signature deep water, standing still */
            background:
                radial-gradient(120% 85% at 88% -12%, rgba(56, 189, 248, 0.45), transparent 58%),
                radial-gradient(95% 70% at -12% 112%, rgba(14, 165, 183, 0.4), transparent 62%),
                linear-gradient(158deg, #0b2f7a 0%, #1746c4 52%, #2563eb 100%);
        }

        /* soft light na follows the cursor */
        .login-left::before {
            content: ""; position: absolute; inset: 0; pointer-events: none; z-index: 1;
            background: radial-gradient(340px circle at var(--mx, 72%) var(--my, 18%), rgba(191,219,254,.14), transparent 65%);
        }
        /* fine ripple grain para the water isn't flat, then a gentle base shading */
        .login-left::after {
            content: ""; position: absolute; inset: 0; pointer-events: none; z-index: 1;
            background:
                radial-gradient(rgba(255, 255, 255, 0.13) 1px, transparent 1.4px) 0 0 / 24px 24px,
                linear-gradient(180deg, rgba(8, 20, 60, 0) 45%, rgba(8, 20, 60, 0.5) 100%);
        }

        .left-content { position: relative; z-index: 2; pointer-events: none; animation: fadeUp .8s cubic-bezier(.2,.8,.2,1) .1s both; }
        .hero { animation-delay: .22s; }
        @keyframes fadeUp { from { opacity: 0; transform: translateY(18px); } to { opacity: 1; transform: none; } }

        .brand { display: flex; align-items: center; gap: 12px; }
        .brand-logo {
            width: 38px; height: 38px;
            border-radius: 11px;
            background: rgba(255, 255, 255, 0.16);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.35), 0 0 0 1px rgba(255, 255, 255, 0.22);
            display: flex; align-items: center; justify-content: center;
            font-family: var(--font-display);
            font-weight: 700; font-size: 1.1rem;
        }
        .brand-name { font-family: var(--font-display); font-weight: 600; font-size: 1.12rem; letter-spacing: .01em; }
        .brand-line { flex: 1; max-width: 140px; height: 1px; background: rgba(255,255,255,.5); margin-left: 4px; }

        .hero { position: relative; margin-top: auto; margin-bottom: auto; }
        .hero h1 {
            font-family: var(--font-display);
            font-weight: 600;
            font-size: clamp(1.8rem, 2.6vw, 2.5rem);
            line-height: 1.14;
            letter-spacing: -.02em;
            text-wrap: balance;
            margin: 0 0 14px;
        }
        .hero p { max-width: 380px; font-size: .92rem; line-height: 1.6; color: rgba(255,255,255,.82); margin: 0; }
        /* slow laser line na sweeps the hero */
        .laser-line {
            position: absolute;
            top: -18px; left: 0;
            width: 40%; height: 1px;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,.9), transparent);
            animation: laser 7s ease-in-out infinite;
        }
        @keyframes laser {
            0%, 18% { transform: translateX(0); opacity: 0; }
            32%, 58% { opacity: .9; }
            72%, 100% { transform: translateX(150%); opacity: 0; }
        }

        /* ===================== RIGHT PANEL — sign-in (kanan) ===================== */
        .login-right {
            position: relative;
            overflow: hidden;
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 32px 24px 24px;
            /* same ambient wash na the dashboard sits on */
            background:
                radial-gradient(680px 380px at 98% -6%, rgba(56, 189, 248, 0.15), transparent 62%),
                radial-gradient(700px 400px at -4% 104%, rgba(99, 102, 241, 0.14), transparent 62%),
                linear-gradient(160deg, #f8faff 0%, #eef2fb 55%, #e8eefb 100%);
        }
        /* soft colour glows + faint dot grid, drifts with the mouse */
        .right-deco { position: absolute; inset: -24px; pointer-events: none; transition: transform .5s ease-out; }
        .right-deco::before {
            content: ""; position: absolute; inset: 0;
            background-image: radial-gradient(rgba(99,102,241,.22) 1.2px, transparent 1.3px);
            background-size: 24px 24px;
            -webkit-mask-image: radial-gradient(ellipse at 50% 50%, transparent 30%, #000 85%);
                    mask-image: radial-gradient(ellipse at 50% 50%, transparent 30%, #000 85%);
        }
        .blob { position: absolute; border-radius: 50%; animation: float 16s ease-in-out infinite; }
        .blob.r1 { width: 380px; height: 380px; top: -130px; right: -90px; background: radial-gradient(circle at 35% 35%, rgba(99,102,241,.30), rgba(99,102,241,0) 70%); }
        .blob.r2 { width: 340px; height: 340px; bottom: -130px; left: -90px; background: radial-gradient(circle at 60% 40%, rgba(37,99,235,.26), rgba(37,99,235,0) 70%); animation-delay: -5s; }
        .blob.r3 { width: 280px; height: 280px; top: 42%; right: -100px; background: radial-gradient(circle, rgba(56,189,248,.26), rgba(56,189,248,0) 70%); animation-delay: -9s; }
        .blob.r4 { width: 260px; height: 260px; top: 18%; left: -90px; background: radial-gradient(circle, rgba(167,139,250,.26), rgba(167,139,250,0) 70%); animation-delay: -3s; }
        @keyframes float {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33%      { transform: translate(10px, -22px) scale(1.04); }
            66%      { transform: translate(-12px, 12px) scale(.97); }
        }

        /* lighter, indigo-tinted bubbles para they read on the pale side */
        .login-right .bubble {
            background: radial-gradient(circle at 30% 26%, rgba(255,255,255,.95), rgba(165,180,252,.38) 48%, rgba(99,102,241,.20) 100%);
            border-color: rgba(99,102,241,.28);
            box-shadow: inset 0 0 14px rgba(255,255,255,.6), inset -4px -6px 10px rgba(99,102,241,.12), 0 8px 18px rgba(99,102,241,.12);
        }
        .login-right .bubble::after { background: rgba(255,255,255,.95); opacity: 1; }
        .login-right .bubble:hover, .login-right .bubble.grabbed { border-color: rgba(99,102,241,.5); }
        .login-right .drop { background: rgba(99,102,241,.55); }

        .right-brand, .login-main, .right-foot { pointer-events: none; position: relative; z-index: 2; }
        .right-brand { display: flex; align-items: center; gap: 9px; font-family: var(--font-display); font-weight: 600; font-size: 1rem; letter-spacing: .01em; color: var(--ink-strong); }
        .right-brand .mark {
            width: 27px; height: 27px;
            border-radius: 8px;
            background: var(--wt-grad);
            color: #fff;
            font-family: var(--font-display);
            font-size: .8rem; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 4px 10px rgba(37,99,235,.3);
        }

        .login-main { flex: 1; width: 100%; display: flex; align-items: center; justify-content: center; padding: 18px 0; }

        /* the dashboard panel: a clean white card sa hairline edge */
        .login-card {
            pointer-events: auto;
            position: relative;
            width: 100%;
            max-width: 420px;
            padding: 34px 32px 28px;
            background: rgba(255, 255, 255, 0.92);
            -webkit-backdrop-filter: blur(18px) saturate(150%);
                    backdrop-filter: blur(18px) saturate(150%);
            border: 1px solid var(--wd-line);
            border-radius: var(--radius);
            box-shadow: var(--wd-sh-lift);
            color: var(--ink-strong);
            animation: cardIn .8s cubic-bezier(.2,.8,.2,1) .2s both;
        }
        @supports not ((-webkit-backdrop-filter: blur(1px)) or (backdrop-filter: blur(1px))) {
            .login-card { background: #F8FAFF; }
        }
        /* thin blue rim light sa top edge */
        .login-card::before {
            content: ""; position: absolute; top: -1px; left: 12%; right: 12%; height: 1px;
            border-radius: 2px;
            background: linear-gradient(90deg, transparent, #93C5FD, transparent);
        }
        @keyframes cardIn { from { opacity: 0; transform: translateY(24px) scale(.97); } to { opacity: 1; transform: none; } }

        .login-card h2 {
            font-family: var(--font-display);
            font-weight: 600;
            font-size: 1.55rem;
            letter-spacing: -.015em;
            line-height: 1.15;
            margin: 0 0 6px;
            color: var(--ink-strong);
        }
        .login-card .sub { font-size: .85rem; line-height: 1.5; color: var(--ink-faint); margin: 0 0 24px; }

        /* inline error / info banner — shown, hindi dismissed */
        .flash {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            font-size: .83rem;
            line-height: 1.45;
            padding: 11px 13px;
            border-radius: 13px;
            margin: 0 0 20px;
            animation: shake .5s cubic-bezier(.36,.07,.19,.97) both;
        }
        .flash i { font-size: .95rem; line-height: 1.35; flex: 0 0 auto; }
        .flash-error { color: #b4263a; background: #fdecef; border: 1px solid #f7cfd8; }
        .flash-info  { color: #1d4ed8; background: #e2ecfe; border: 1px solid #c9dcfc; }
        @keyframes shake {
            10%, 90% { transform: translateX(-1px); } 20%, 80% { transform: translateX(2px); }
            30%, 50%, 70% { transform: translateX(-4px); } 40%, 60% { transform: translateX(4px); }
        }

        .field { margin-bottom: 18px; }
        .field-label {
            display: block;
            font-size: .8rem;
            font-weight: 600;
            color: #33415A;
            margin-bottom: 7px;
        }
        .field-wrap { position: relative; }
        .field-input {
            width: 100%;
            height: 48px;
            padding: 0 14px;
            border: 1px solid var(--wt-border);
            border-radius: 13px;
            background: #F8FAFC;
            font-family: var(--font-mono);
            font-size: .88rem;
            letter-spacing: .01em;
            color: var(--ink-strong);
            outline: none;
            transition: border-color .15s, box-shadow .15s, background .15s;
        }
        .field-error {
            display: flex; align-items: center; gap: 6px;
            margin-top: 7px; font-size: .8125rem; font-weight: 500; color: #b4263a;
        }
        .field.is-invalid .field-input { border-color: #e5738a; background: #fff; }
        .field.is-invalid .field-input:focus { border-color: #d9475f; box-shadow: 0 0 0 4px rgba(217, 71, 95, .13); }
        .field-input::placeholder { color: #7D8CA0; font-family: var(--font-ui); letter-spacing: 0; }
        .field-wrap:hover .field-input { border-color: #C3CFE3; }
        .field-input:focus { background: #fff; border-color: var(--wt-blue); box-shadow: 0 0 0 4px rgba(37, 99, 235, .13); }
        .field-wrap.has-icon .field-input { padding-left: 42px; }
        .field-wrap.has-toggle .field-input { padding-right: 52px; }
        .field-icon {
            position: absolute; left: 15px; top: 50%; transform: translateY(-50%);
            color: #94A3B8; font-size: 1rem; pointer-events: none; transition: color .15s;
        }
        .field-wrap:focus-within .field-icon { color: var(--wt-blue); }
        .toggle-pw {
            position: absolute; right: 7px; top: 50%; transform: translateY(-50%);
            width: 38px; height: 38px;
            display: grid; place-items: center;
            border: 0; background: transparent;
            color: var(--ink-faint); font-size: 1.02rem;
            border-radius: 10px; cursor: pointer;
            transition: background .15s, color .15s;
        }
        .toggle-pw:hover { background: #EAF1FE; color: var(--wt-blue); }

        .caps-hint {
            display: none;
            align-items: center;
            gap: 7px;
            font-size: .75rem;
            font-weight: 500;
            color: #8A5A12;
            margin: -8px 0 16px;
        }
        .caps-hint.show { display: flex; }

        .login-row { display: flex; align-items: center; justify-content: flex-end; margin: -4px 0 22px; }
        .forgot {
            background: none; border: 0; padding: 0;
            font-size: .8rem; font-weight: 600;
            color: var(--wt-blue);
            cursor: pointer; text-decoration: none;
        }
        .forgot:hover { color: var(--blue-deep); text-decoration: underline; }

        .btn-login {
            position: relative;
            overflow: hidden;
            width: 100%;
            height: 50px;
            border: 0;
            border-radius: 13px;
            background: var(--wt-grad);
            color: #fff;
            font-family: var(--font-display);
            font-size: .94rem;
            font-weight: 600;
            letter-spacing: .01em;
            box-shadow: 0 10px 22px -10px rgba(37, 99, 235, .7), inset 0 1px 0 rgba(255, 255, 255, .22);
            cursor: pointer;
            transition: transform .15s, box-shadow .15s, filter .15s;
        }
        .btn-login:hover { transform: translateY(-1px); box-shadow: 0 14px 26px -12px rgba(37, 99, 235, .8), inset 0 1px 0 rgba(255, 255, 255, .22); filter: brightness(1.04); }
        .btn-login:active { transform: translateY(0); }
        /* shine sweep (dumaraan na liwanag) */
        .btn-login::before {
            content: ""; position: absolute; top: 0; left: -60%; width: 42%; height: 100%;
            background: linear-gradient(100deg, transparent, rgba(255,255,255,.38), transparent);
            transform: skewX(-20deg); transition: left .65s ease;
        }
        .btn-login:hover::before { left: 130%; }
        /* loading state — class lang, so login_btn still posts */
        .btn-login.loading { color: transparent; pointer-events: none; }
        .btn-login.loading::after {
            content: ""; position: absolute; inset: 0; margin: auto;
            width: 20px; height: 20px;
            border: 2.5px solid rgba(255,255,255,.4); border-top-color: #fff; border-radius: 50%;
            animation: spin .7s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .secure {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin: 18px 0 0;
            font-size: .72rem;
            letter-spacing: .04em;
            color: var(--ink-faint);
        }
        .secure i { font-size: .8rem; color: var(--wt-blue); }

        .right-foot {
            font-family: var(--font-mono);
            font-size: .72rem;
            letter-spacing: .01em;
            color: var(--ink-faint);
            text-align: center;
        }

        a:focus-visible, button:focus-visible, input:focus-visible { outline: 3px solid rgba(37, 99, 235, .55); outline-offset: 2px; border-radius: var(--radius); }

        /* ===================== FORGOT PASSWORD DIALOG (popup) ===================== */
        .fp-modal {
            border: 0;
            border-radius: 20px;
            padding: 28px 26px 22px;
            background: #fff;
            box-shadow: var(--wd-sh-lift);
            font-family: var(--font-ui);
        }
        .fp-head { text-align: center; margin-bottom: 20px; }
        .fp-icon {
            display: grid; place-items: center;
            width: 52px; height: 52px; margin: 0 auto 14px;
            border-radius: 16px;
            background: #EAF1FE; color: var(--wt-blue);
            font-size: 1.35rem;
        }
        .fp-head h3 {
            font-family: var(--font-display);
            font-size: 1.28rem; font-weight: 600; letter-spacing: -.015em;
            line-height: 1.2; color: var(--ink-strong); margin: 0 0 8px;
        }
        .fp-head p { font-size: .84rem; line-height: 1.55; color: var(--ink-faint); margin: 0; }
        .fp-cancel {
            display: block; width: 100%; margin-top: 10px;
            border: 0; padding: 9px;
            background: none; border-radius: 11px;
            font-family: var(--font-ui); font-size: .82rem; font-weight: 600;
            color: var(--ink-faint); cursor: pointer;
            transition: color .15s, background .15s;
        }
        .fp-cancel:hover { color: var(--blue-deep); background: #F1F5FB; }

        /* ===================== RESPONSIVE (para small screens) ===================== */
        @media (max-width: 900px) {
            .login-wrap { flex-direction: column; }
            .login-left { flex: 0 0 auto; min-height: 250px; padding: 26px 24px 30px; gap: 40px; }
            .hero h1 { font-size: 1.5rem; margin-bottom: 0; }
            .hero p, .laser-line { display: none; }
            .login-right { padding: 24px 16px 22px; }
            .login-card { padding: 28px 22px 24px; }
        }

        @media (prefers-reduced-motion: reduce) {
            .blob, .drop, .login-left, .left-content, .login-card, .flash, .laser-line { animation: none !important; transition: none !important; }
            .right-deco { transition: none; }
        }
    </style>
</head>
<body>

<div class="login-wrap">

    <!-- LEFT: identity + bubbles (kaliwa) -->
    <aside class="login-left">
        <div class="bubbles" aria-hidden="true">
            <span class="bubble soft"></span>
            <span class="bubble soft"></span>
            <span class="bubble"></span>
            <span class="bubble"></span>
            <span class="bubble soft"></span>
            <span class="bubble"></span>
            <span class="bubble"></span>
            <span class="bubble"></span>
            <span class="bubble"></span>
        </div>

        <div class="left-content brand">
            <div class="brand-logo"><svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><g transform="rotate(-18 16 16)"><path d="M16 16c-3.7-.9-5.8-4.5-4.6-7.8C12.8 4.1 19.2 4.1 20.6 8.2c1.2 3.3-.9 6.9-4.6 7.8Z" fill="currentColor"/><path d="M16 16c3.7.9 5.8 4.5 4.6 7.8-1.4 4.1-7.8 4.1-9.2 0-1.2-3.3.9-6.9 4.6-7.8Z" fill="currentColor" fill-opacity=".7"/></g><path d="M24.6 5.4c.4 2.8 1.4 3.8 4.2 4.2-2.8.4-3.8 1.4-4.2 4.2-.4-2.8-1.4-3.8-4.2-4.2 2.8-.4 3.8-1.4 4.2-4.2Z" fill="currentColor"/><path d="M8.4 20.6c.25 1.9.95 2.6 2.85 2.85-1.9.25-2.6.95-2.85 2.85-.25-1.9-.95-2.6-2.85-2.85 1.9-.25 2.6-.95 2.85-2.85Z" fill="currentColor" fill-opacity=".9"/></svg></div>
            <span class="brand-name">Sparkling Spin</span>
            <span class="brand-line"></span>
        </div>

        <div class="left-content hero">
            <span class="laser-line" aria-hidden="true"></span>
            <h1>Run your laundry shop<br>with confidence.</h1>
            <p>Track orders, manage customers and monitor daily transactions, all in one place.</p>
        </div>
    </aside>

    <!-- RIGHT: sign-in (kanan) -->
    <main class="login-right">
        <div class="right-deco" aria-hidden="true">
            <span class="blob r1"></span>
            <span class="blob r2"></span>
            <span class="blob r3"></span>
            <span class="blob r4"></span>
        </div>
        <div class="bubbles" aria-hidden="true">
            <span class="bubble"></span>
            <span class="bubble"></span>
            <span class="bubble"></span>
            <span class="bubble"></span>
            <span class="bubble"></span>
            <span class="bubble"></span>
            <span class="bubble"></span>
        </div>

        <div class="right-brand">
            <span class="mark"><svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><g transform="rotate(-18 16 16)"><path d="M16 16c-3.7-.9-5.8-4.5-4.6-7.8C12.8 4.1 19.2 4.1 20.6 8.2c1.2 3.3-.9 6.9-4.6 7.8Z" fill="currentColor"/><path d="M16 16c3.7.9 5.8 4.5 4.6 7.8-1.4 4.1-7.8 4.1-9.2 0-1.2-3.3.9-6.9 4.6-7.8Z" fill="currentColor" fill-opacity=".7"/></g><path d="M24.6 5.4c.4 2.8 1.4 3.8 4.2 4.2-2.8.4-3.8 1.4-4.2 4.2-.4-2.8-1.4-3.8-4.2-4.2 2.8-.4 3.8-1.4 4.2-4.2Z" fill="currentColor"/><path d="M8.4 20.6c.25 1.9.95 2.6 2.85 2.85-1.9.25-2.6.95-2.85 2.85-.25-1.9-.95-2.6-2.85-2.85 1.9-.25 2.6-.95 2.85-2.85Z" fill="currentColor" fill-opacity=".9"/></svg></span>
            <span>Sparkling Spin</span>
        </div>

        <div class="login-main">
            <section class="login-card" aria-labelledby="signin-title">
                <h2 id="signin-title">Welcome back</h2>
                <p class="sub">Sign in to your account to continue</p>

                <?php if ($banner): ?>
                    <div class="flash flash-<?php echo $banner[1]; ?>" role="alert" aria-live="assertive">
                        <i class="bi <?php echo $banner[1] === 'error' ? 'bi-exclamation-octagon' : 'bi-info-circle'; ?>" aria-hidden="true"></i>
                        <span><?php echo htmlspecialchars($banner[0]); ?></span>
                    </div>
                <?php endif; ?>

                <form action="login_process.php" method="POST" novalidate>
                    <div class="field">
                        <label for="username" class="field-label">Username</label>
                        <div class="field-wrap has-icon">
                            <i class="bi bi-person field-icon" aria-hidden="true"></i>
                            <input type="text" name="username" id="username" class="field-input"
                                   required autofocus autocomplete="username"
                                   placeholder="Enter username" spellcheck="false" autocapitalize="none">
                        </div>
                    </div>

                    <div class="field">
                        <label for="password" class="field-label">Password</label>
                        <div class="field-wrap has-icon has-toggle">
                            <i class="bi bi-lock field-icon" aria-hidden="true"></i>
                            <input type="password" name="password" id="password" class="field-input"
                                   required autocomplete="current-password"
                                   placeholder="Enter password" aria-describedby="capsHint">
                            <button type="button" class="toggle-pw" id="togglePw" aria-label="Show password" aria-pressed="false">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="caps-hint" id="capsHint" aria-live="polite">
                        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Caps Lock is on
                    </div>

                    <div class="login-row">
                        <button type="button" class="forgot" id="forgotLink">Forgot password?</button>
                    </div>

                    <button type="submit" name="login_btn" class="btn-login">Login</button>
                </form>

                <p class="secure"><i class="bi bi-shield-lock" aria-hidden="true"></i> Secure sign-in</p>
            </section>
        </div>

        <div class="right-foot">
            &copy; <?php echo date('Y'); ?> Sparkling Spin &middot; Laundry Shop Management System
        </div>
    </main>
</div>

<!-- Forgot password: records a reset request for the administrator -->
<div class="modal fade" id="forgotModal" tabindex="-1" aria-labelledby="forgotTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content fp-modal">
            <form action="reset_request.php" method="POST" novalidate>
                <div class="fp-head">
                    <span class="fp-icon" aria-hidden="true"><i class="bi bi-key"></i></span>
                    <h3 id="forgotTitle">Forgot your password?</h3>
                    <p>Enter your username and we'll ask the administrator to set a new password for you. This system has no reset email.</p>
                </div>

                <?php if ($reset_in_modal && $reset_flash): ?>
                    <div class="flash flash-error" role="alert">
                        <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
                        <span><?php echo htmlspecialchars($reset_flash[0]); ?></span>
                    </div>
                <?php endif; ?>

                <div class="field">
                    <label for="fpUsername" class="field-label">Username</label>
                    <div class="field-wrap has-icon">
                        <i class="bi bi-person field-icon" aria-hidden="true"></i>
                        <input type="text" name="username" id="fpUsername" class="field-input"
                               required maxlength="50" autocomplete="username"
                               placeholder="Enter username" spellcheck="false" autocapitalize="none">
                    </div>
                </div>

                <button type="submit" class="btn-login">Send reset request</button>
                <button type="button" class="fp-cancel" data-bs-dismiss="modal">Back to sign in</button>
            </form>
        </div>
    </div>
</div>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script>
    // Show / hide password (ipakita / itago)
    (function () {
        var btn = document.getElementById('togglePw');
        var input = document.getElementById('password');
        if (!btn || !input) return;
        btn.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.innerHTML = show ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
    })();

    // Interactive bubbles (both panels): drag & throw them, tapos click to pop.
    // The bubbles sit behind ng content, so the form stays fully usable.
    (function () {
        var still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        // [diameter px, centre x (fraction of panel), centre y (fraction of panel)] - same order as the markup
        var leftCfg  = [[300,.98,.03],[240,.02,.98],[90,.70,.20],[52,.46,.11],[170,1,.50],[40,.70,.70],[84,.88,.84],[24,.91,.32],[30,.42,.86]];
        var rightCfg = [[120,.06,.22],[64,.90,.14],[38,.95,.52],[96,.07,.80],[50,.84,.88],[28,.14,.52],[22,.64,.07]];

        function initBubbles(panel, cfg) {
            var layer = panel.querySelector('.bubbles');
            if (!layer) return;
            var els = Array.prototype.slice.call(layer.querySelectorAll('.bubble'));
            var W = panel.clientWidth, H = panel.clientHeight;

            var bubbles = els.map(function (el, i) {
                var size = cfg[i][0], r = size / 2, a = Math.random() * Math.PI * 2, sp = 14 + Math.random() * 16;
                el.style.width = el.style.height = size + 'px';
                return { el: el, r: r, m: r * r, x: cfg[i][1] * W, y: cfg[i][2] * H,
                         vx: Math.cos(a) * sp, vy: Math.sin(a) * sp, s: still ? 1 : 0, o: 1,
                         state: still ? 'idle' : 'spawning', wait: still ? 0 : .25 + i * .09, t: 0, grabbed: false };
            });

            function draw(b) {
                b.el.style.transform = 'translate3d(' + (b.x - b.r) + 'px,' + (b.y - b.r) + 'px,0) scale(' + b.s + ')';
                b.el.style.opacity = b.o;
            }
            bubbles.forEach(draw);
            if (still) return;

            function burst(b) {
                for (var i = 0; i < 8; i++) {
                    var d = document.createElement('span'), ang = (i / 8) * Math.PI * 2 + Math.random() * .5, dist = b.r * (.7 + Math.random() * .8) + 14;
                    d.className = 'drop';
                    d.style.setProperty('--x', (b.x - 3) + 'px');
                    d.style.setProperty('--y', (b.y - 3) + 'px');
                    d.style.setProperty('--dx', Math.cos(ang) * dist + 'px');
                    d.style.setProperty('--dy', Math.sin(ang) * dist + 'px');
                    d.addEventListener('animationend', function () { d.remove(); });
                    layer.appendChild(d);
                }
            }
            function pop(b) {
                b.state = 'popping'; b.t = 0; b.grabbed = false;
                b.el.classList.remove('grabbed');
                b.el.style.pointerEvents = 'none';
                burst(b);
                // pressure wave na nudges nearby bubbles
                bubbles.forEach(function (o) {
                    if (o === b || o.grabbed || (o.state !== 'idle' && o.state !== 'spawning')) return;
                    var dx = o.x - b.x, dy = o.y - b.y, d = Math.hypot(dx, dy) || 1, reach = b.r + o.r + 140;
                    if (d < reach) { var k = (1 - d / reach) * 320 * Math.min(1, 90 / o.r + .4); o.vx += dx / d * k; o.vy += dy / d * k; }
                });
            }

            // ---- pointer handling (mouse + touch) ----
            bubbles.forEach(function (b) {
                var off, start, moved, hist;
                function local(e) { var p = panel.getBoundingClientRect(); return { x: e.clientX - p.left, y: e.clientY - p.top }; }

                b.el.addEventListener('pointerdown', function (e) {
                    if (b.state !== 'idle' && b.state !== 'spawning') return;
                    e.preventDefault();
                    b.el.setPointerCapture(e.pointerId);
                    var p = local(e);
                    off = { x: b.x - p.x, y: b.y - p.y };
                    start = { x: p.x, y: p.y, t: performance.now() };
                    moved = 0; hist = [{ x: p.x, y: p.y, t: start.t }];
                    b.grabbed = true; b.vx = b.vy = 0;
                    b.el.classList.add('grabbed');
                });
                b.el.addEventListener('pointermove', function (e) {
                    if (!b.grabbed) return;
                    var p = local(e), now = performance.now();
                    moved = Math.max(moved, Math.hypot(p.x - start.x, p.y - start.y));
                    b.x = Math.min(W, Math.max(0, p.x + off.x));
                    b.y = Math.min(H, Math.max(0, p.y + off.y));
                    hist.push({ x: p.x, y: p.y, t: now });
                    while (hist.length > 2 && now - hist[0].t > 90) hist.shift();
                });
                function release(e) {
                    if (!b.grabbed) return;
                    b.grabbed = false;
                    b.el.classList.remove('grabbed');
                    if (e.type === 'pointerup' && moved < 6 && performance.now() - start.t < 450) { pop(b); return; }
                    var a = hist[0], z = hist[hist.length - 1], dt = Math.max((z.t - a.t) / 1000, .016);
                    b.vx = Math.max(-900, Math.min(900, (z.x - a.x) / dt));
                    b.vy = Math.max(-900, Math.min(900, (z.y - a.y) / dt));
                }
                b.el.addEventListener('pointerup', release);
                b.el.addEventListener('pointercancel', release);
            });

            // ---- physics loop (repeated motion) ----
            var prev = performance.now();
            function tick(now) {
                var dt = Math.min((now - prev) / 1000, .033); prev = now;
                var live = [];

                bubbles.forEach(function (b) {
                    if (b.state === 'popping') {
                        b.t += dt; var p = Math.min(b.t / .22, 1);
                        b.s = 1 + .35 * p; b.o = 1 - p;
                        if (p >= 1) { b.state = 'gone'; b.t = 0; b.o = 0; }
                        draw(b); return;
                    }
                    if (b.state === 'gone') {
                        b.t += dt;
                        if (b.t > 1.3) {   // comes back somewhere new
                            var a = Math.random() * Math.PI * 2, sp = 14 + Math.random() * 16;
                            b.x = b.r + Math.random() * Math.max(W - 2 * b.r, 1);
                            b.y = b.r + Math.random() * Math.max(H - 2 * b.r, 1);
                            b.vx = Math.cos(a) * sp; b.vy = Math.sin(a) * sp;
                            b.s = 0; b.o = 1; b.wait = 0; b.state = 'spawning';
                            b.el.style.pointerEvents = 'auto';
                        }
                        return;
                    }
                    if (b.state === 'spawning') {
                        if (b.wait > 0) { b.wait -= dt; draw(b); return; }   // staggered intro
                        b.s = Math.min(1, b.s + dt * 2.5);
                        if (b.s >= 1) b.state = 'idle';
                    }

                    if (!b.grabbed) {
                        b.x += b.vx * dt; b.y += b.vy * dt;
                        var sp = Math.hypot(b.vx, b.vy);
                        if (sp > 22) { var k = Math.pow(.55, dt); b.vx *= k; b.vy *= k; }          // thrown bubbles slow down
                        else if (sp < 12) { var an = Math.random() * 6.283; b.vx += Math.cos(an) * 20 * dt; b.vy += Math.sin(an) * 20 * dt; } // keeps drifting
                        if (b.x < 0)  { b.x = 0;  b.vx =  Math.abs(b.vx) * .9; }
                        if (b.x > W)  { b.x = W;  b.vx = -Math.abs(b.vx) * .9; }
                        if (b.y < 0)  { b.y = 0;  b.vy =  Math.abs(b.vy) * .9; }
                        if (b.y > H)  { b.y = H;  b.vy = -Math.abs(b.vy) * .9; }
                    }
                    live.push(b);
                });

                // bubble-to-bubble bumps (a grabbed bubble can't be pushed)
                for (var i = 0; i < live.length; i++) for (var j = i + 1; j < live.length; j++) {
                    var A = live[i], B = live[j];
                    var dx = B.x - A.x, dy = B.y - A.y, min = (A.r + B.r) * .92, d = Math.hypot(dx, dy);
                    if (d >= min || d === 0) continue;
                    var nx = dx / d, ny = dy / d, over = (min - d) * .25;
                    var wa = A.grabbed ? 0 : B.m / (A.m + B.m), wb = B.grabbed ? 0 : A.m / (A.m + B.m);
                    var tot = wa + wb || 1;
                    A.x -= nx * over * wa / tot * 2; A.y -= ny * over * wa / tot * 2;
                    B.x += nx * over * wb / tot * 2; B.y += ny * over * wb / tot * 2;
                    var rel = (B.vx - A.vx) * nx + (B.vy - A.vy) * ny;
                    if (rel < 0) {
                        var imp = -(1 + .85) * rel / ((A.grabbed ? 0 : 1 / A.m) + (B.grabbed ? 0 : 1 / B.m));
                        if (!A.grabbed) { A.vx -= imp * nx / A.m; A.vy -= imp * ny / A.m; }
                        if (!B.grabbed) { B.vx += imp * nx / B.m; B.vy += imp * ny / B.m; }
                    }
                }
                live.forEach(draw);
                requestAnimationFrame(tick);
            }
            requestAnimationFrame(tick);

            // keep bubbles proportionate kapag the window is resized
            if (window.ResizeObserver) {
                new ResizeObserver(function () {
                    var nw = panel.clientWidth, nh = panel.clientHeight;
                    if (!nw || !nh) return;
                    bubbles.forEach(function (b) { b.x *= nw / W; b.y *= nh / H; });
                    W = nw; H = nh;
                }).observe(panel);
            }
        }

        initBubbles(document.querySelector('.login-left'), leftCfg);
        initBubbles(document.querySelector('.login-right'), rightCfg);

        // faint cursor light sa left panel
        if (!still) {
            document.querySelector('.login-left').addEventListener('pointermove', function (e) {
                var p = this.getBoundingClientRect();
                this.style.setProperty('--mx', (e.clientX - p.left) + 'px');
                this.style.setProperty('--my', (e.clientY - p.top) + 'px');
            });
        }
    })();

    // Caps lock hint (paalala)
    (function () {
        var pw = document.getElementById('password');
        var hint = document.getElementById('capsHint');
        if (!pw || !hint) return;
        function caps(e) {
            if (e.getModifierState) hint.classList.toggle('show', e.getModifierState('CapsLock'));
        }
        pw.addEventListener('keydown', caps);
        pw.addEventListener('keyup', caps);
        pw.addEventListener('blur', function () { hint.classList.remove('show'); });
    })();

    // Submit: spinner via class only (hindi disabled ang button, so login_btn still posts)
    // Empty required fields get a message under the field instead of the browser's own popup (novalidate ang forms).
    function wtCheckRequired(form) {
        var first = null;
        [].forEach.call(form.querySelectorAll('input[required]'), function (input) {
            var field = input.closest('.field'), wrap = input.closest('.field-wrap');
            if (!field || !wrap) return;
            var err = field.querySelector('.field-error');
            if (input.value.trim() === '') {
                if (!err) {
                    var lab = field.querySelector('.field-label');
                    err = document.createElement('div');
                    err.className = 'field-error';
                    err.setAttribute('role', 'alert');
                    err.innerHTML = '<i class="bi bi-exclamation-circle" aria-hidden="true"></i><span></span>';
                    err.lastChild.textContent = 'Please enter your ' + (lab ? lab.textContent.trim().toLowerCase() : 'details') + '.';
                    wrap.insertAdjacentElement('afterend', err);
                    input.addEventListener('input', function () { field.classList.remove('is-invalid'); if (err.parentNode) err.parentNode.removeChild(err); });
                }
                field.classList.add('is-invalid');
                input.setAttribute('aria-invalid', 'true');
                if (!first) first = input;
            } else {
                field.classList.remove('is-invalid');
                input.removeAttribute('aria-invalid');
                if (err && err.parentNode) err.parentNode.removeChild(err);
            }
        });
        if (first) first.focus();
        return !first;
    }

    (function () {
        var form = document.querySelector('.login-card form');
        var btn = form ? form.querySelector('.btn-login') : null;
        if (!form || !btn) return;
        var sent = false;
        form.addEventListener('submit', function (e) {
            if (sent) { e.preventDefault(); return; }
            if (!wtCheckRequired(form)) { e.preventDefault(); return; }
            sent = true;
            btn.classList.add('loading');
        });
        window.addEventListener('pageshow', function () { sent = false; btn.classList.remove('loading'); });
    })();

    // Soft parallax sa right dot-grid + blobs
    (function () {
        var deco = document.querySelector('.right-deco');
        if (deco && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            document.addEventListener('pointermove', function (e) {
                deco.style.transform = 'translate(' + ((e.clientX / innerWidth - .5) * -22) + 'px,' + ((e.clientY / innerHeight - .5) * -22) + 'px)';
            });
        }
    })();

    // Forgot password: open the dialog, carrying over anything na already typed.
    (function () {
        var link = document.getElementById('forgotLink');
        var box  = document.getElementById('forgotModal');
        if (!link || !box) return;
        var fpForm = box.querySelector('form');
        if (fpForm) fpForm.addEventListener('submit', function (e) { if (!wtCheckRequired(fpForm)) e.preventDefault(); });
        var loginUser = document.getElementById('username');
        var fpUser    = document.getElementById('fpUsername');
        link.addEventListener('click', function () {
            if (fpUser && loginUser && !fpUser.value.trim()) fpUser.value = loginUser.value.trim();
            bootstrap.Modal.getOrCreateInstance(box).show();
        });
        box.addEventListener('shown.bs.modal', function () { if (fpUser) fpUser.focus(); });
        // A failed request (?reset=nouser|error) reopens the dialog with its message.
        if (<?php echo $reset_in_modal ? 'true' : 'false'; ?>) bootstrap.Modal.getOrCreateInstance(box).show();
    })();
</script>
</body>
</html>