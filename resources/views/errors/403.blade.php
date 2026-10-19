<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>403 — Access Denied · {{ config('app.name', 'HSB Projects') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        html, body {
            height: 100%;
            min-height: 100%;
            background: #ffffff;
        }

        body {
            min-height: 100vh;
            min-height: 100dvh;
            font-family: Inter, system-ui, -apple-system, sans-serif;
            color: #1e1b4b;
            overflow-x: hidden;
        }

        .page {
            width: 100%;
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: clamp(0.75rem, 2.5vh, 1.5rem);
            padding: clamp(1rem, 4vh, 2.5rem) clamp(1rem, 4vw, 2rem);
            text-align: center;
        }

        .art {
            width: min(92vw, 640px);
            animation: fadeUp 0.7s ease-out both;
        }

        .art-inner {
            width: 100%;
            animation: floatSoft 5.5s ease-in-out infinite;
        }

        .art img {
            display: block;
            width: 100%;
            height: auto;
            user-select: none;
            pointer-events: none;
        }

        .copy {
            max-width: 28rem;
            animation: fadeUp 0.7s ease-out 0.12s both;
        }

        .copy h1 {
            font-size: clamp(1.7rem, 4.2vw, 2.35rem);
            font-weight: 800;
            letter-spacing: -0.02em;
            color: #1e1b4b;
        }

        .copy p {
            margin-top: 0.75rem;
            color: #64748b;
            font-size: clamp(0.95rem, 2.2vw, 1.05rem);
            line-height: 1.55;
        }

        .actions {
            position: relative;
            z-index: 20;
            animation: fadeUp 0.7s ease-out 0.24s both;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 11rem;
            height: 3.1rem;
            padding: 0 1.85rem;
            border: 0;
            border-radius: 0.9rem;
            background: linear-gradient(180deg, #38bdf8, #0ea5e9);
            color: #fff;
            font: inherit;
            font-size: 1.05rem;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            box-shadow: 0 10px 28px rgba(14, 165, 233, 0.35);
            transition: transform 0.2s ease, box-shadow 0.2s ease, filter 0.2s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .btn:hover {
            transform: translateY(-2px) scale(1.03);
            box-shadow: 0 14px 32px rgba(14, 165, 233, 0.48);
            filter: brightness(1.05);
        }

        .btn:active { transform: translateY(0) scale(0.98); }

        .btn:focus-visible {
            outline: 3px solid rgba(14, 165, 233, 0.55);
            outline-offset: 3px;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes floatSoft {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-8px); }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>
<body>
    <main class="page" role="main" aria-labelledby="error-title">
        <div class="art" aria-hidden="true">
            <div class="art-inner">
                <img
                    src="{{ asset('images/errors/403-illustration.png') }}?v=3"
                    alt=""
                    width="1024"
                    height="253"
                >
            </div>
        </div>

        <section class="copy">
            <h1 id="error-title">We are Sorry...</h1>
            <p>
                The page you’re trying to access has restricted access.<br>
                Please refer to your system administrator.
            </p>
        </section>

        <div class="actions">
            <a class="btn" id="go-back" href="{{ url('/') }}">Go Back</a>
        </div>
    </main>

    <script>
        (function () {
            var button = document.getElementById('go-back');
            if (!button) return;

            button.addEventListener('click', function (event) {
                event.preventDefault();

                var referrer = document.referrer;
                try {
                    if (
                        referrer
                        && new URL(referrer).origin === window.location.origin
                        && referrer !== window.location.href
                        && referrer.indexOf('/error/') === -1
                    ) {
                        window.location.href = referrer;
                        return;
                    }
                } catch (e) {}

                if (window.history.length > 1) {
                    window.history.back();
                    return;
                }

                window.location.href = button.getAttribute('href') || '/';
            });
        })();
    </script>
</body>
</html>
