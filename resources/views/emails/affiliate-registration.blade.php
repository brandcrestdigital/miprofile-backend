<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Affiliate Registration</title>
</head>

<body>

    <h2>Welcome, {{ $affiliate->name }}!</h2>

    <p>
        Thank you for registering for our Affiliate Program.
    </p>

    <p>
        We have successfully received your application.
    </p>

    <p>
        Your application is currently <strong>under review</strong>
        by our admin team.
    </p>

    <p>
        Once your application has been reviewed and approved,
        you will receive another email with your login credentials.
    </p>

    <p>
        <strong>Registration Details:</strong>
    </p>

    <p>
        Email: {{ $affiliate->email }}<br>
        Phone: {{ $affiliate->phone }}<br>
        Status: <strong>Under Review</strong>
    </p>

    <p>
        Thank you for your interest in our Affiliate Program.
    </p>

    <p>
        Regards,<br>
        {{ config('app.name') }}
    </p>

</body>
</html>