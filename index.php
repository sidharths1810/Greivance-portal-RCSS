<?php
// Start session and include database connection
session_start();
require_once 'db_connect.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="Rajagiri College of Social Sciences - Grievance Redressal Portal. Submit and track grievances securely.">
  <meta name="theme-color" content="#DB0878">
  <title>Rajagiri College of Social Sciences - Grievance Redressal Portal</title>
  <link rel="icon" type="image/svg+xml" href="public/favicon.svg">

  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700;12..96,800&family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet">

  <!-- Tailwind CSS CDN -->
  <script src="https://cdn.tailwindcss.com"></script>

  <!-- Lucide Icons CDN -->
  <script src="https://unpkg.com/lucide@latest"></script>

  <!-- Custom Tailwind Theme Config -->
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            brandPurple: '#4A154B',
            brandPink: '#E5097F',
            brandGreen: '#006837',
            brandGold: '#C5A059',
            hot: '#DB0878',      // hero magenta (brand pink, deepened for text contrast)
            hotdark: '#B70664',
            sun: '#FFC93C',      // call-to-action yellow
            grape: '#4A154B',    // brand purple
            leaf: '#006837',     // brand green
            ink: '#1F0A24',      // text and hard shadows
            blush: '#FFF0F7'
          },
          fontFamily: {
            display: ['"Bricolage Grotesque"', 'system-ui', 'sans-serif'],
            sans: ['Figtree', 'system-ui', 'sans-serif']
          }
        }
      }
    }
  </script>

  <!-- Local Page CSS Link -->
  <link rel="stylesheet" href="assets/css/index.css">

  <!-- Design layer -->
  <style>
    html { scroll-behavior: smooth; }

    /* Visible keyboard focus everywhere (keeps each element's own corner radius) */
    a:focus-visible, button:focus-visible {
      outline: 3px solid #1F0A24;
      outline-offset: 3px;
    }
    .on-hot a:focus-visible, .on-hot button:focus-visible,
    .on-ink a:focus-visible, .on-ink button:focus-visible { outline-color: #fff; }
    .on-hot .picker a:focus-visible { outline-color: #1F0A24; } /* white card inside the magenta hero */
    .on-sun a:focus-visible, .on-sun button:focus-visible { outline-color: #1F0A24; }

    .balance { text-wrap: balance; }

    /* Signature device: hard offset shadow on primary actions */
    .btn-hard {
      border: 2px solid #1F0A24;
      box-shadow: 4px 4px 0 #1F0A24;
      transition: transform .12s ease, box-shadow .12s ease, background-color .15s ease;
    }
    .btn-hard:hover  { transform: translate(2px, 2px); box-shadow: 2px 2px 0 #1F0A24; }
    .btn-hard:active { transform: translate(4px, 4px); box-shadow: 0 0 0 #1F0A24; }

    /* Portal picker card */
    .picker { border: 2px solid #1F0A24; box-shadow: 10px 10px 0 #FFC93C; }
    @media (max-width: 640px) { .picker { box-shadow: 6px 6px 0 #FFC93C; } }

    /* Role rows: each role carries its own colour */
    .role-chip {
      background: var(--tint);
      color: var(--ic);
      transition: background-color .2s ease, color .2s ease;
    }
    .role-link:hover .role-chip,
    .role-link:focus-visible .role-chip { background: var(--c); color: var(--on); }

    .role-link-lg {
      border: 2px solid #ECE4EE;
      transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
    }
    .role-link-lg:hover {
      border-color: #1F0A24;
      box-shadow: 4px 4px 0 var(--c);
      transform: translate(-2px, -2px);
    }

    /* One orchestrated entrance on page load */
    @keyframes rise {
      from { opacity: 0; transform: translateY(16px); }
      to   { opacity: 1; transform: none; }
    }
    .rise   { animation: rise .6s cubic-bezier(.2, .7, .2, 1) both; }
    .rise-2 { animation-delay: .12s; }
    .rise-3 { animation-delay: .24s; }

    /* Brief cue on the picker when "File / Track a grievance" is pressed */
    @keyframes picker-ping {
      0%, 100% { transform: none; box-shadow: 10px 10px 0 #FFC93C; }
      40%      { transform: translate(-4px, -4px); box-shadow: 16px 16px 0 #FFC93C; }
    }
    .is-pinged { animation: picker-ping .7s ease-out 2; }

    @media (prefers-reduced-motion: reduce) {
      html { scroll-behavior: auto; }
      *, *::before, *::after { animation: none !important; transition: none !important; }
    }
  </style>
</head>
<body class="min-h-screen bg-white text-slate-700 font-sans antialiased selection:bg-sun selection:text-ink">

  <!-- Oréll Grievance logo (replaces the old PNG; reused in header and footer) -->
  <svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <symbol id="orel-logo" viewBox="0 0 128 40" style="overflow:visible">
      <path fill="#DB0878" d="M10 0H26A10 10 0 0 1 36 10V20A10 10 0 0 1 26 30H16L8 38V29.8A10 10 0 0 1 0 20V10A10 10 0 0 1 10 0Z"/>
      <path d="M10 15.5l5.5 5.5L27 9.5" fill="none" stroke="#FFC93C" stroke-width="3.6" stroke-linecap="round" stroke-linejoin="round"/>
      <text x="46" y="21" font-family="Bricolage Grotesque, system-ui, sans-serif" font-size="23" font-weight="800" fill="#1F0A24">Oréll</text>
      <text x="46.5" y="35" font-family="Figtree, system-ui, sans-serif" font-size="11" font-weight="700" letter-spacing="0.6" fill="#DB0878">Grievance</text>
    </symbol>
  </svg>

  <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-[60] focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-ink focus:shadow-lg">
    Skip to main content
  </a>

  <!-- Header -->
  <header class="sticky top-0 z-50 bg-white border-b border-slate-200">
    <!-- Brand colour stripe -->
    <div class="flex h-1.5" aria-hidden="true">
      <span class="flex-1 bg-hot"></span>
      <span class="flex-1 bg-sun"></span>
      <span class="flex-1 bg-leaf"></span>
      <span class="flex-1 bg-grape"></span>
    </div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex h-16 md:h-20 items-center justify-between">

        <!-- Logo -->
        <a href="index.php" class="flex items-center gap-4 rounded-lg">
          <img
            src="public/rcss-logo.png"
            alt="RCSS Logo"
            class="h-10 md:h-12 w-auto"
          />
          <span class="hidden sm:block h-8 w-px bg-slate-200" aria-hidden="true"></span>
          <svg class="hidden sm:block h-9 md:h-10 w-auto" width="128" height="40" viewBox="0 0 128 40" role="img" aria-label="Oréll Grievance">
            <use href="#orel-logo"></use>
          </svg>
        </a>

        <!-- Desktop Navigation -->
        <nav class="hidden md:flex items-center gap-2" aria-label="Main">
          <a
            href="#"
            class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-blush hover:text-hot"
          >
            UGC Guidelines
          </a>
          <a
            href="#contact"
            class="rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-blush hover:text-hot"
          >
            Contact
          </a>

          <!-- Login Dropdown -->
          <div class="relative ml-2" id="login-dropdown-container">
            <button
              id="login-dropdown-btn"
              type="button"
              aria-haspopup="true"
              aria-expanded="false"
              aria-controls="login-dropdown-menu"
              class="inline-flex cursor-pointer items-center gap-2 rounded-lg bg-hot px-5 py-2.5 text-sm font-bold text-white transition-colors hover:bg-hotdark"
            >
              <span>Login</span>
              <i data-lucide="chevron-down" id="login-chevron" class="h-4 w-4 transition-transform duration-200"></i>
            </button>

            <div id="login-dropdown-menu" class="hidden absolute right-0 z-50 mt-3 w-80 rounded-xl border-2 border-ink bg-white p-2 shadow-[6px_6px_0_#FFC93C]" role="menu">
              <p class="px-3 pt-2 pb-1 text-xs font-semibold text-slate-500">Login as</p>

              <a href="login.php?role=student" role="menuitem" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-700 transition-colors hover:bg-blush hover:text-ink">
                <i data-lucide="graduation-cap" class="h-4 w-4 text-hot"></i>
                <span class="font-semibold">Students</span>
              </a>
              <a href="login.php?role=parent" role="menuitem" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-700 transition-colors hover:bg-blush hover:text-ink">
                <i data-lucide="users" class="h-4 w-4 text-[#B37A00]"></i>
                <span class="font-semibold">Parents</span>
              </a>
              <a href="login.php?role=teacher" role="menuitem" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-700 transition-colors hover:bg-blush hover:text-ink">
                <i data-lucide="briefcase" class="h-4 w-4 text-leaf"></i>
                <span class="font-semibold">Teachers &amp; Non-Teaching Staff</span>
              </a>

              <div class="my-2 border-t border-slate-200" role="separator"></div>

              <a href="login.php?role=management" role="menuitem" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-700 transition-colors hover:bg-blush hover:text-ink">
                <i data-lucide="layers" class="h-4 w-4 text-grape"></i>
                <span class="font-semibold">Grievance Member</span>
              </a>
              <a href="login.php?role=admin" role="menuitem" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-700 transition-colors hover:bg-blush hover:text-ink">
                <i data-lucide="lock" class="h-4 w-4 text-ink"></i>
                <span class="font-semibold">Admin</span>
              </a>
            </div>
          </div>
        </nav>

        <!-- Mobile Menu Button -->
        <button
          id="mobile-menu-btn"
          type="button"
          aria-label="Toggle menu"
          aria-expanded="false"
          aria-controls="mobile-menu"
          class="md:hidden cursor-pointer rounded-lg p-2 text-hot transition-colors hover:bg-blush"
        >
          <i data-lucide="menu" id="mobile-menu-icon" class="h-6 w-6"></i>
        </button>
      </div>
    </div>

    <!-- Mobile Menu Drawer -->
    <div id="mobile-menu" class="hidden md:hidden border-t border-slate-200 bg-white shadow-lg">
      <div class="px-4 py-4">
        <a href="#" class="block rounded-lg px-4 py-3 text-sm font-semibold text-slate-700 transition-colors hover:bg-blush hover:text-hot">
          UGC Guidelines
        </a>
        <a href="#contact" class="block rounded-lg px-4 py-3 text-sm font-semibold text-slate-700 transition-colors hover:bg-blush hover:text-hot">
          Contact
        </a>

        <div class="mt-3 border-t border-slate-200 pt-3">
          <p class="mb-1 px-4 text-xs font-semibold text-slate-500">Login as</p>
          <a href="login.php?role=student" class="flex items-center gap-3 rounded-lg px-4 py-3 text-sm font-semibold text-slate-700 transition-colors hover:bg-blush hover:text-ink">
            <i data-lucide="graduation-cap" class="h-4 w-4 text-hot"></i>
            <span>Students</span>
          </a>
          <a href="login.php?role=parent" class="flex items-center gap-3 rounded-lg px-4 py-3 text-sm font-semibold text-slate-700 transition-colors hover:bg-blush hover:text-ink">
            <i data-lucide="users" class="h-4 w-4 text-[#B37A00]"></i>
            <span>Parents</span>
          </a>
          <a href="login.php?role=teacher" class="flex items-center gap-3 rounded-lg px-4 py-3 text-sm font-semibold text-slate-700 transition-colors hover:bg-blush hover:text-ink">
            <i data-lucide="briefcase" class="h-4 w-4 text-leaf"></i>
            <span>Teachers &amp; Non-Teaching Staff</span>
          </a>
          <div class="my-2 border-t border-slate-200" role="separator"></div>
          <a href="login.php?role=management" class="flex items-center gap-3 rounded-lg px-4 py-3 text-sm font-semibold text-slate-700 transition-colors hover:bg-blush hover:text-ink">
            <i data-lucide="layers" class="h-4 w-4 text-grape"></i>
            <span>Grievance Member</span>
          </a>
          <a href="login.php?role=admin" class="flex items-center gap-3 rounded-lg px-4 py-3 text-sm font-semibold text-slate-700 transition-colors hover:bg-blush hover:text-ink">
            <i data-lucide="lock" class="h-4 w-4 text-ink"></i>
            <span>Admin</span>
          </a>
        </div>
      </div>
    </div>
  </header>

  <main id="main">

    <!-- Hero + portal picker -->
    <section class="on-hot relative overflow-hidden bg-hot">
      <!-- Decorative shapes -->
      <div class="pointer-events-none absolute -bottom-32 -left-24 h-80 w-80 rounded-full bg-grape" aria-hidden="true"></div>
      <div class="pointer-events-none absolute -top-28 right-[30%] hidden h-72 w-72 rounded-full border-[36px] border-white/10 lg:block" aria-hidden="true"></div>

      <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-14 md:py-20 lg:py-24">
        <div class="grid items-center gap-14 lg:grid-cols-12 lg:gap-10">

          <!-- Left: message and primary actions -->
          <div class="lg:col-span-7">
            <h1 class="rise font-display balance max-w-2xl text-[2.6rem] sm:text-6xl xl:text-[4.25rem] font-extrabold leading-[1.02] tracking-tight text-white">
              Fair, transparent and prompt grievance resolution
            </h1>

            <p class="rise rise-2 mt-6 max-w-xl text-lg sm:text-xl leading-relaxed text-white">
              Your voice matters. Submit your concerns with confidence and track their resolution in real time through our transparent and secure platform.
            </p>

            <div class="rise rise-3 mt-10 flex flex-col gap-4 sm:flex-row">
              <button
                data-scroll-to="choose-portal"
                type="button"
                class="btn-hard inline-flex cursor-pointer items-center justify-center gap-2.5 rounded-xl bg-sun px-7 py-4 text-base font-bold text-ink hover:bg-[#FFD35C]"
              >
                <i data-lucide="file-text" class="h-5 w-5"></i>
                <span>File a grievance</span>
              </button>
              <button
                data-scroll-to="choose-portal"
                type="button"
                class="inline-flex cursor-pointer items-center justify-center gap-2.5 rounded-xl border-2 border-white px-7 py-4 text-base font-bold text-white transition-colors hover:bg-white hover:text-hot"
              >
                <i data-lucide="clock" class="h-5 w-5"></i>
                <span>Track a grievance</span>
              </button>
            </div>
          </div>

          <!-- Right: portal picker -->
          <div class="rise rise-2 lg:col-span-5">
            <div id="choose-portal" class="picker scroll-mt-28 rounded-2xl bg-white p-5 sm:p-7">
              <h2 class="font-display text-2xl font-bold text-ink">Choose your portal</h2>
              <p class="mt-1 text-sm text-slate-600">Select your role to sign in and continue.</p>

              <div class="mt-5 space-y-3">

                <!-- Student -->
                <a
                  href="login.php?role=student"
                  style="--c:#DB0878; --tint:#FFE0F0; --ic:#DB0878; --on:#fff"
                  class="role-link role-link-lg group flex items-center gap-4 rounded-xl p-4"
                >
                  <span class="role-chip flex h-12 w-12 shrink-0 items-center justify-center rounded-xl">
                    <i data-lucide="graduation-cap" class="h-6 w-6"></i>
                  </span>
                  <span class="min-w-0 flex-1">
                    <span class="block font-bold text-ink">Student Portal</span>
                    <span class="block text-sm text-slate-600">Submit and track your grievances.</span>
                  </span>
                  <i data-lucide="arrow-right" class="h-5 w-5 shrink-0 text-slate-400 transition-transform group-hover:translate-x-1 group-hover:text-ink"></i>
                </a>

                <!-- Parent -->
                <a
                  href="login.php?role=parent"
                  style="--c:#FFC93C; --tint:#FFF0C2; --ic:#8A5600; --on:#1F0A24"
                  class="role-link role-link-lg group flex items-center gap-4 rounded-xl p-4"
                >
                  <span class="role-chip flex h-12 w-12 shrink-0 items-center justify-center rounded-xl">
                    <i data-lucide="users" class="h-6 w-6"></i>
                  </span>
                  <span class="min-w-0 flex-1">
                    <span class="block font-bold text-ink">Parent Portal</span>
                    <span class="block text-sm text-slate-600">Submit and follow grievances about your ward.</span>
                  </span>
                  <i data-lucide="arrow-right" class="h-5 w-5 shrink-0 text-slate-400 transition-transform group-hover:translate-x-1 group-hover:text-ink"></i>
                </a>

                <!-- Teachers & Non-Teaching Staff -->
                <a
                  href="login.php?role=teacher"
                  style="--c:#006837; --tint:#DCEFE4; --ic:#006837; --on:#fff"
                  class="role-link role-link-lg group flex items-center gap-4 rounded-xl p-4"
                >
                  <span class="role-chip flex h-12 w-12 shrink-0 items-center justify-center rounded-xl">
                    <i data-lucide="briefcase" class="h-6 w-6"></i>
                  </span>
                  <span class="min-w-0 flex-1">
                    <span class="block font-bold text-ink">Teachers &amp; Non-Teaching Staff</span>
                    <span class="block text-sm text-slate-600">Submit and manage staff grievances.</span>
                  </span>
                  <i data-lucide="arrow-right" class="h-5 w-5 shrink-0 text-slate-400 transition-transform group-hover:translate-x-1 group-hover:text-ink"></i>
                </a>
              </div>

              <!-- Secondary: committee & administration -->
              <div class="mt-5 border-t-2 border-slate-100 pt-4">
                <p class="mb-1 px-3 text-sm font-semibold text-slate-500">Committee and administration</p>

                <a
                  href="login.php?role=management"
                  style="--c:#4A154B; --tint:#EEE1EF; --ic:#4A154B; --on:#fff"
                  class="role-link group flex items-center gap-3 rounded-lg px-3 py-2.5 transition-colors hover:bg-blush"
                >
                  <span class="role-chip flex h-9 w-9 shrink-0 items-center justify-center rounded-lg">
                    <i data-lucide="layers" class="h-[18px] w-[18px]"></i>
                  </span>
                  <span class="min-w-0 flex-1">
                    <span class="block text-sm font-bold text-ink">Grievance Member</span>
                    <span class="block text-sm text-slate-600">Review and resolve grievances as a committee member.</span>
                  </span>
                  <i data-lucide="arrow-right" class="h-4 w-4 shrink-0 text-slate-400 transition-transform group-hover:translate-x-1 group-hover:text-ink"></i>
                </a>

                <a
                  href="login.php?role=admin"
                  style="--c:#1F0A24; --tint:#E8E4EA; --ic:#1F0A24; --on:#fff"
                  class="role-link group flex items-center gap-3 rounded-lg px-3 py-2.5 transition-colors hover:bg-blush"
                >
                  <span class="role-chip flex h-9 w-9 shrink-0 items-center justify-center rounded-lg">
                    <i data-lucide="lock" class="h-[18px] w-[18px]"></i>
                  </span>
                  <span class="min-w-0 flex-1">
                    <span class="block text-sm font-bold text-ink">Admin Portal</span>
                    <span class="block text-sm text-slate-600">Manage users, settings and all grievances.</span>
                  </span>
                  <i data-lucide="arrow-right" class="h-4 w-4 shrink-0 text-slate-400 transition-transform group-hover:translate-x-1 group-hover:text-ink"></i>
                </a>
              </div>
            </div>

            <p class="mt-7 flex items-center justify-center gap-2 text-sm font-semibold text-white lg:justify-start">
              <i data-lucide="lock" class="h-4 w-4 shrink-0 text-sun"></i>
              <span>Your identity and complaint details are kept confidential.</span>
            </p>
          </div>
        </div>
      </div>
    </section>

    <!-- Why choose our portal -->
    <section class="bg-white py-20 md:py-28" aria-labelledby="why-heading">
      <div class="max-w-7xl mx-auto grid gap-12 px-4 sm:px-6 lg:grid-cols-12 lg:gap-16 lg:px-8">

        <div class="lg:col-span-4">
          <div class="lg:sticky lg:top-32">
            <h2 id="why-heading" class="font-display balance text-3xl md:text-5xl font-extrabold leading-[1.05] tracking-tight text-ink">
              Why choose our portal?
            </h2>
            <p class="mt-5 max-w-sm text-lg leading-relaxed text-slate-600">
              Built on principles of transparency, security, and efficiency.
            </p>
          </div>
        </div>

        <div class="grid gap-x-10 gap-y-12 sm:grid-cols-2 lg:col-span-8">

          <!-- Highlight 1 -->
          <div class="border-t-4 border-hot pt-6">
            <span class="mb-5 flex h-12 w-12 items-center justify-center rounded-xl bg-hot text-white">
              <i data-lucide="shield" class="h-6 w-6"></i>
            </span>
            <h3 class="font-display text-xl font-bold text-ink">100% Confidentiality</h3>
            <p class="mt-2 leading-relaxed text-slate-600">
              Your identity and complaint details are protected with enterprise-grade security and encryption protocols.
            </p>
          </div>

          <!-- Highlight 2 -->
          <div class="border-t-4 border-leaf pt-6">
            <span class="mb-5 flex h-12 w-12 items-center justify-center rounded-xl bg-leaf text-white">
              <i data-lucide="check-circle" class="h-6 w-6"></i>
            </span>
            <h3 class="font-display text-xl font-bold text-ink">UGC Norms Compliant</h3>
            <p class="mt-2 leading-relaxed text-slate-600">
              Fully aligned with University Grants Commission guidelines and regulatory requirements.
            </p>
          </div>

          <!-- Highlight 3 -->
          <div class="border-t-4 border-grape pt-6">
            <span class="mb-5 flex h-12 w-12 items-center justify-center rounded-xl bg-grape text-white">
              <i data-lucide="layers" class="h-6 w-6"></i>
            </span>
            <h3 class="font-display text-xl font-bold text-ink">Two-Tier Resolution System</h3>
            <p class="mt-2 leading-relaxed text-slate-600">
              A structured escalation process that ensures thorough investigation and fair resolution at every level.
            </p>
          </div>

          <!-- Highlight 4 -->
          <div class="border-t-4 border-sun pt-6">
            <span class="mb-5 flex h-12 w-12 items-center justify-center rounded-xl bg-sun text-ink">
              <i data-lucide="clock" class="h-6 w-6"></i>
            </span>
            <h3 class="font-display text-xl font-bold text-ink">Resolution Within Fixed Timelines</h3>
            <p class="mt-2 leading-relaxed text-slate-600">
              Committed to timely resolution with transparent progress tracking and regular updates.
            </p>
          </div>

        </div>
      </div>
    </section>

    <!-- Closing call to action -->
    <section class="on-sun bg-sun py-14 md:py-16">
      <div class="max-w-7xl mx-auto flex flex-col gap-8 px-4 sm:px-6 md:flex-row md:items-center md:justify-between lg:px-8">
        <div class="max-w-2xl">
          <h2 class="font-display balance text-3xl md:text-4xl font-extrabold leading-tight tracking-tight text-ink">
            Have a concern? Raise it today.
          </h2>
          <p class="mt-3 text-lg text-ink/80">
            Choose your portal, file your grievance, and follow it through to resolution.
          </p>
        </div>
        <button
          data-scroll-to="choose-portal"
          type="button"
          class="btn-hard inline-flex shrink-0 cursor-pointer items-center justify-center gap-2.5 rounded-xl bg-ink px-7 py-4 text-base font-bold text-white hover:bg-hot"
        >
          <i data-lucide="file-text" class="h-5 w-5"></i>
          <span>File a grievance</span>
        </button>
      </div>
    </section>

  </main>

  <!-- Footer -->
  <footer id="contact" class="on-ink scroll-mt-24 bg-ink py-14 text-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="grid grid-cols-1 gap-10 md:grid-cols-12 md:gap-8">

        <div class="md:col-span-6">
          <div class="inline-flex items-center gap-4 rounded-xl bg-white px-4 py-3">
            <img
              src="public/rcss-logo.png"
              alt="RCSS Logo"
              class="h-10 w-auto"
            />
            <svg class="h-8 w-auto" width="128" height="40" viewBox="0 0 128 40" role="img" aria-label="Oréll Grievance">
              <use href="#orel-logo"></use>
            </svg>
          </div>
          <p class="mt-5 max-w-md text-sm leading-relaxed text-slate-300">
            Rajagiri College of Social Sciences - Committed to excellence in grievance redressal, powered by Oréll.
          </p>
        </div>

        <div class="md:col-span-3">
          <h3 class="mb-4 font-display text-lg font-bold text-sun">Quick Links</h3>
          <ul class="space-y-3 text-sm">
            <li>
              <button
                data-scroll-to="choose-portal"
                type="button"
                class="cursor-pointer rounded text-left text-slate-200 transition-colors hover:text-white hover:underline underline-offset-4"
              >
                File a Grievance
              </button>
            </li>
            <li>
              <a href="login.php?role=student" class="rounded text-slate-200 transition-colors hover:text-white hover:underline underline-offset-4">
                Track Status
              </a>
            </li>
            <li>
              <a href="#" class="rounded text-slate-200 transition-colors hover:text-white hover:underline underline-offset-4">
                UGC Guidelines
              </a>
            </li>
          </ul>
        </div>

        <div class="md:col-span-3">
          <h3 class="mb-4 font-display text-lg font-bold text-sun">Contact</h3>
          <ul class="space-y-3 text-sm text-slate-200">
            <li class="flex items-start gap-3">
              <i data-lucide="mail" class="mt-0.5 h-4 w-4 shrink-0 text-sun"></i>
              <a href="mailto:grievance@rajarigircss.edu" class="break-all rounded transition-colors hover:text-white hover:underline underline-offset-4">grievance@rajarigircss.edu</a>
            </li>
            <li class="flex items-start gap-3">
              <i data-lucide="phone" class="mt-0.5 h-4 w-4 shrink-0 text-sun"></i>
              <span>+91 484 XXX XXXX</span>
            </li>
            <li class="flex items-start gap-3">
              <i data-lucide="map-pin" class="mt-0.5 h-4 w-4 shrink-0 text-sun"></i>
              <span>Aluva, Kochi, Kerala</span>
            </li>
          </ul>
        </div>
      </div>

      <div class="mt-12 border-t border-white/15 pt-6 text-sm text-slate-400 md:text-center">
        <p>&copy; <?php echo date('Y'); ?> Rajagiri College of Social Sciences. All rights reserved.</p>
      </div>
    </div>
  </footer>

  <!-- Cue the portal card when a "File / Track a grievance" button is pressed (scrolling itself is handled by index.js) -->
  <script>
    document.addEventListener('click', function (e) {
      var trigger = e.target.closest('[data-scroll-to="choose-portal"]');
      if (!trigger) return;
      var card = document.getElementById('choose-portal');
      if (!card) return;
      card.classList.remove('is-pinged');
      void card.offsetWidth; // restart the animation
      card.classList.add('is-pinged');
      setTimeout(function () { card.classList.remove('is-pinged'); }, 1600);
    });
  </script>

  <!-- Page Scripts -->
  <script src="assets/js/index.js"></script>
</body>
</html>