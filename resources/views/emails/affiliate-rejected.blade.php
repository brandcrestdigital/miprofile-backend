<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Affiliate Application Update</title>
</head>

<body>

    <h2>Hello {{ $affiliate->name }},</h2>

    <p>
        Thank you for your interest in our Affiliate Program.
    </p>

    <p>
        After reviewing your application, we are unable to approve
        your affiliate registration at this time.
    </p>

    <p>
        <strong>Reason for rejection:</strong>
    </p>

    <p>
        {{ $rejectionRemarks }}
    </p>

    <p>
        You may review the above reason and contact our support team
        if you have any questions.
    </p>

    <p>
        Regards,<br>
        {{ config('app.name') }}
    </p>

</body>
</html>