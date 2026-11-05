<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Continue to payment</title>
</head>
<body>
    <form id="payment" method="POST" action="{{ $url }}">
        @foreach ($fields as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ (string) $value }}">
        @endforeach
        <button type="submit">Continue to payment</button>
    </form>
    <script>document.getElementById('payment').submit();</script>
</body>
</html>
