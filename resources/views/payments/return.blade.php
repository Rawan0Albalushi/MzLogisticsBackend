<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: "Segoe UI", Tahoma, sans-serif;
            background: #f5f6f8;
            color: #10212c;
        }
        .card {
            width: min(440px, calc(100% - 32px));
            background: #fff;
            border: 1px solid #d8e0e6;
            border-radius: 12px;
            padding: 28px 24px;
            text-align: center;
        }
        h1 { font-size: 22px; margin: 0 0 8px; }
        p { color: #5b6b76; line-height: 1.6; margin: 0 0 20px; }
        a {
            display: inline-block;
            background: #0e2a38;
            color: #fff;
            text-decoration: none;
            border-radius: 10px;
            padding: 12px 18px;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <main class="card">
        <h1>{{ $title }}</h1>
        <p>{{ $body }}</p>
        <a id="return-link" href="{{ $deep_link }}">{{ $button }}</a>
    </main>
    <script>
        const deepLink = @json($deep_link);
        function returnToApp() {
            try {
                window.location.replace(deepLink);
            } catch (e) {}
        }
        returnToApp();
        setTimeout(returnToApp, 300);
        setTimeout(returnToApp, 1200);
    </script>
</body>
</html>
