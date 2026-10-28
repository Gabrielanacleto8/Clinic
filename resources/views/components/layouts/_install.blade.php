@php $iconVersion = app(App\Services\ClinicLogo::class)->version(); @endphp
{{-- Instalação na tela inicial do celular (Android e iPhone) e ícone da aba. --}}
<link rel="manifest" href="{{ route('pwa.manifest') }}">
<meta name="theme-color" content="#0f766e">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
<link rel="icon" type="image/png" href="{{ route('clinic.icon', array_filter(['size' => 192, 'v' => $iconVersion])) }}">
<link rel="apple-touch-icon" href="{{ route('clinic.icon', array_filter(['size' => 180, 'v' => $iconVersion])) }}">
