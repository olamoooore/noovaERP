<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Website Builder - {{ $website->name }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    
    <script>
        // Redirect to the React frontend editor
        // This view is kept for backwards compatibility
        window.location.href = '{{ config('app.frontend_url', 'http://localhost:5173') }}/website-builder/{{ $website->id }}';
    </script>
</head>
<body>
    <p>Redirecting to editor...</p>
</body>
</html>
