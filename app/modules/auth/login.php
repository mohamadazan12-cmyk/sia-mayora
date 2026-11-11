<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Login - PT Mayora Indah Tbk</title>
<!-- Tailwind CSS CDN with forms plugin -->
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            brandRed: '#d50000',
            brandDarkRed: '#b80000',
            labelColor: '#374151',
            inputBorder: '#e2e8f0',
            subtleText: '#6b7280'
          },
          fontFamily: {
            sans: ['Inter', 'system-ui', '-apple-system', 'BlinkMacSystemFont', '"Segoe UI"', 'Roboto', 'sans-serif'],
            serifLogo: ['"Times New Roman"', 'Times', 'serif']
          }
        }
      }
    }
  </script>
<style data-purpose="custom-layout">
    body {
      background-color: #ffffff;
      -webkit-font-smoothing: antialiased;
    }
  </style>
</head>
<body class="min-h-screen flex flex-col justify-between text-gray-900 select-none">
<!-- BEGIN: TopHeader -->
<header class="w-full pt-8 pl-10 md:pt-10 md:pl-14">
<!-- Branding Header with red bar indicator -->
<div class="flex items-center gap-2.5">
<span aria-hidden="true" class="w-[3.5px] h-[19px] bg-brandRed rounded-sm inline-block"></span>
<h1 class="text-[15px] font-semibold tracking-tight text-gray-900">
        PT Mayora Indah Tbk
      </h1>
</div>
</header>
<!-- END: TopHeader -->
<!-- BEGIN: MainContent -->
<main class="flex-grow flex flex-col items-center justify-center -mt-12 px-4">
<!-- Login Card Container -->
<div class="w-full max-w-[456px] bg-white rounded-2xl border border-gray-200/90 shadow-[0_2px_12px_rgba(0,0,0,0.03)] px-9 py-10" data-purpose="login-card">
<!-- MAYORA Logo Header -->
<div class="flex items-center justify-center gap-3.5 mb-9" data-purpose="company-logo">
<!-- SVG Emblem matching Mayora iconic sun-burst lines and twin arches -->
<svg aria-label="Mayora Icon" class="w-11 h-11 shrink-0" fill="none" viewbox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">
<!-- Stylized sun / rays at top -->
<ellipse cx="32" cy="18" fill="#d50000" rx="9" ry="9"></ellipse>
<line stroke="#ffffff" stroke-width="1.8" x1="20" x2="44" y1="13" y2="13"></line>
<line stroke="#ffffff" stroke-width="1.8" x1="18" x2="46" y1="16.5" y2="16.5"></line>
<line stroke="#ffffff" stroke-width="1.8" x1="18.5" x2="45.5" y1="20" y2="20"></line>
<line stroke="#ffffff" stroke-width="1.8" x1="21" x2="43" y1="23.5" y2="23.5"></line>
<!-- Twin stylized petal / column shapes -->
<path d="M14 27.5C14 27.5 24 28 30 33.5V47H14V27.5Z" fill="#d50000"></path>
<path d="M50 27.5C50 27.5 40 28 34 33.5V47H50V27.5Z" fill="#d50000"></path>
</svg>
<!-- Brand Text Wordmark -->
<span class="text-brandRed font-serifLogo font-bold text-[34px] tracking-wide leading-none select-none">
          MAYORA
        </span>
</div>

<?php 
$errorMessage = !empty($loginError) ? $loginError : getFlash('error');
if (!empty($errorMessage)): 
?>
<!-- Error Message -->
<div class="mb-5 p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-[13px] flex items-center gap-2" role="alert">
  <svg class="w-4 h-4 text-brandRed shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
    <circle cx="12" cy="12" r="10"></circle>
    <line x1="12" y1="8" x2="12" y2="12"></line>
    <line x1="12" y1="16" x2="12.01" y2="16"></line>
  </svg>
  <span><?= e($errorMessage) ?></span>
</div>
<?php endif; ?>

<?php 
$successMessage = getFlash('success');
if (!empty($successMessage)): 
?>
<!-- Success Message -->
<div class="mb-5 p-3 rounded-lg bg-green-50 border border-green-200 text-green-700 text-[13px] flex items-center gap-2" role="alert">
  <svg class="w-4 h-4 text-green-600 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
  </svg>
  <span><?= e($successMessage) ?></span>
</div>
<?php endif; ?>

<!-- Login Form -->
<form class="space-y-5" data-purpose="login-form" method="POST" action="index.php?page=login">
<?= csrfField() ?>
<!-- Username Field -->
<div>
<label class="block text-[13.5px] font-medium text-gray-700 mb-1.5" for="username">
            Username
          </label>
<input class="w-full px-3.5 py-2.5 text-[14px] text-gray-900 placeholder:text-gray-400 bg-white border border-gray-300 rounded-lg focus:outline-none focus:ring-1 focus:ring-brandRed focus:border-brandRed transition-colors" id="username" name="username" placeholder="Masukkan username Anda" type="text" value="<?= isset($username) ? e($username) : '' ?>" required autofocus/>
</div>
<!-- Password Field -->
<div>
<label class="block text-[13.5px] font-medium text-gray-700 mb-1.5" for="password">
            Password
          </label>
<div class="relative">
<input class="w-full px-3.5 py-2.5 pr-11 text-[14px] text-gray-900 placeholder:text-gray-400 bg-white border border-gray-300 rounded-lg focus:outline-none focus:ring-1 focus:ring-brandRed focus:border-brandRed transition-colors" id="password" name="password" placeholder="Masukkan password Anda" type="password" required/>
<!-- Password visibility toggle button -->
<button aria-label="Toggle password visibility" class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-gray-400 hover:text-gray-600 focus:outline-none" id="togglePassword" type="button">
<!-- Eye Outline Icon -->
<svg class="h-5 w-5 stroke-current" fill="none" stroke-width="1.75" viewbox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
<path d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" stroke-linecap="round" stroke-linejoin="round"></path>
<path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" stroke-linecap="round" stroke-linejoin="round"></path>
</svg>
</button>
</div>
</div>
<!-- Submit Button -->
<div class="pt-2">
<button class="w-full bg-brandRed hover:bg-brandDarkRed text-white font-medium text-[14.5px] py-2.5 px-4 rounded-lg shadow-sm transition duration-150 ease-in-out text-center active:scale-[0.99]" type="submit">
            Masuk
          </button>
</div>
</form>
</div>
<!-- Security note below the card -->
<div class="mt-6 flex items-center justify-center gap-1.5 text-gray-500 text-[13px]" data-purpose="security-note">
<!-- Lock outline icon -->
<svg class="w-3.5 h-3.5 text-gray-500 stroke-current mb-0.5" fill="none" stroke-width="2" viewbox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
<path d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" stroke-linecap="round" stroke-linejoin="round"></path>
</svg>
<span>Akses Internal Perusahaan</span>
</div>
</main>
<!-- END: MainContent -->
<!-- BEGIN: FooterSpacer -->
<footer class="h-10"></footer>
<!-- END: FooterSpacer -->
<!-- JavaScript for Password Visibility Toggle -->
<script data-purpose="toggle-password-visibility">
    document.addEventListener('DOMContentLoaded', () => {
      const toggleBtn = document.getElementById('togglePassword');
      const passwordInput = document.getElementById('password');

      if (toggleBtn && passwordInput) {
        const eyeIcon = `<svg class="h-5 w-5 stroke-current" fill="none" stroke-width="1.75" viewbox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" stroke-linecap="round" stroke-linejoin="round"></path><path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" stroke-linecap="round" stroke-linejoin="round"></path></svg>`;
        const eyeOffIcon = `<svg class="h-5 w-5 stroke-current" fill="none" stroke-width="1.75" viewbox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" stroke-linecap="round" stroke-linejoin="round"></path></svg>`;

        toggleBtn.addEventListener('click', () => {
          const isPassword = passwordInput.getAttribute('type') === 'password';
          passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
          toggleBtn.innerHTML = isPassword ? eyeOffIcon : eyeIcon;
        });
      }
    });
  </script>
</body>
</html>
